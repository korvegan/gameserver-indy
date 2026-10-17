<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Fixed campaign rosters and explicit all-members goals, using the existing event ledger. */
final class Challenges
{
    private ?string $reconciledHash=null;
    public function __construct(private readonly SliceRuntime $runtime,private readonly MemberProfiles $members,
        private readonly AccessPolicy $policy,private readonly CommunityLedger $ledger,private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games) {}
    private function path(): string { return $this->runtime->directory.'/data/community-challenges.json'; }
    private function read(): array
    {
        $this->runtime->assertInitialized();
        if (!is_file($this->path())) { return ['version'=>1,'campaigns'=>[],'goals'=>[]]; }
        $data=json_decode(PrivateFiles::read($this->path(),4194304),true,flags:JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null)!==1 || !is_array($data['campaigns'] ?? null) || !is_array($data['goals'] ?? null)) { throw new \DomainException('Herausforderungen nicht lesbar.'); }
        return $data;
    }
    private function change(callable $change): mixed
    {
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('community-challenges'); $lock->acquire(true);
        try {
            $data=$this->read(); $result=$change($data); PrivateFiles::directory(dirname($this->path()));
            PrivateFiles::write($this->path(),json_encode($data,JSON_THROW_ON_ERROR)); return $result;
        } finally { $lock->release(); }
    }
    private function allowed(Principal $actor,array $context,string $permission='community.read'): void
    { $this->policy->require($actor,$permission,$context['instance_id'] ?? null); }
    public function view(Principal $actor): array
    {
        $this->policy->require($actor,'community.read'); $data=$this->read();
        foreach (['campaigns','goals'] as $key) {
            $data[$key]=array_values(array_filter($data[$key],fn($row)=>($row['instance_id'] ?? null)===null || $actor->allows('status.read',$row['instance_id'])));
        }
        foreach ($data['goals'] as &$goal) {
            $goal['achieved']=count(array_filter($goal['evidence'],fn($proof)=>$proof['state']==='achieved'));
            $goal['total']=count($goal['member_ids']); unset($goal['event'],$goal['history']);
        }
        return $data;
    }
    public function campaign(Principal $actor,string $id): array
    {
        foreach ($this->read()['campaigns'] as $row) { if ($row['id']===$id) { $this->allowed($actor,$row); return $row; } }
        throw new \InvalidArgumentException('Unbekannte feste Kampagne.');
    }
    public function isFixed(string $id): bool { return in_array($id,array_column($this->read()['campaigns'],'id'),true); }
    public function createCampaign(Principal $actor,\stdClass $body): array
    {
        $this->policy->require($actor,'access.manage');
        if (array_diff(array_keys(get_object_vars($body)),['name','game_id','instance_id','member_ids']) || !is_array($body->member_ids ?? null)) { throw new \InvalidArgumentException('Kampagne, Spiel und festen Mitgliederkreis angeben.'); }
        $name=ChronicleValues::text($body->name ?? '',100,true); $game=$body->game_id ?? null; $instance=($body->instance_id ?? '') ?: null;
        if (!is_string($game) || !in_array($game,array_column($this->games->entries($this->registry->settings()->catalog_games ?? []),'id'),true)
            || $instance!==null && (!is_string($instance) || $this->registry->get($instance)->game!==$game)) { throw new \InvalidArgumentException('Spiel und Serverzuordnung prüfen.'); }
        $names=$this->members->selected($body->member_ids);
        if (!$names) { throw new \InvalidArgumentException('Mindestens ein Kampagnenmitglied auswählen.'); }
        $row=['id'=>'campaign-'.bin2hex(random_bytes(12)),'name'=>$name,'game_id'=>$game,'instance_id'=>$instance,
            'member_ids'=>array_keys($names),'member_names'=>$names,'created_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        $this->change(function(array &$data) use ($row) { $data['campaigns'][]=$row; });
        return ['message'=>'Kampagne mit festem Mitgliederkreis angelegt. Für andere Beteiligte eine neue Kampagne anlegen.','campaign_id'=>$row['id']];
    }
    public function createGoal(Principal $actor,\stdClass $body): array
    {
        $this->policy->require($actor,'access.manage');
        if (array_diff(array_keys(get_object_vars($body)),['campaign_id','title','badge','rule_version']) || !is_string($body->campaign_id ?? null)
            || !is_int($body->rule_version ?? null) || $body->rule_version<1 || $body->rule_version>999) { throw new \InvalidArgumentException('Kampagne, Regelversion, Ziel und Auszeichnung angeben.'); }
        $campaign=$this->campaign($actor,$body->campaign_id); $evidence=[];
        foreach ($campaign['member_ids'] as $id) { $evidence[$id]=['state'=>'unknown','source'=>'none','occurred_at'=>'','observed_at'=>null]; }
        $goal=['id'=>'goal-'.bin2hex(random_bytes(12)),'campaign_id'=>$campaign['id'],'campaign_label'=>$campaign['name'],
            'game_id'=>$campaign['game_id'],'instance_id'=>$campaign['instance_id'],'member_ids'=>$campaign['member_ids'],'member_names'=>$campaign['member_names'],
            'title'=>ChronicleValues::text($body->title ?? '',160,true),'badge'=>ChronicleValues::text($body->badge ?? '',80,true),
            'rule'=>'all-members','rule_version'=>$body->rule_version,'evidence'=>$evidence,'history'=>[],'completed_at'=>null,'withdrawn'=>false];
        $this->change(function(array &$data) use ($goal) { $data['goals'][]=$goal; });
        return ['message'=>'Sammelziel angelegt. Unbekannte Nachweise bleiben offen.','goal_id'=>$goal['id']];
    }
    /** Only trusted future adapters pass non-manual sources; the HTTP endpoint always uses manual. */
    public function observe(Principal $actor,string $id,string $member,string $state,string $occurred='',string $note='',string $source='manual'): array
    {
        if (!in_array($state,['achieved','not_achieved','unknown','private','error'],true) || !in_array($source,['manual','steam-import','verified-game'],true)) { throw new \InvalidArgumentException('Nachweiszustand oder Quelle ungültig.'); }
        $occurred=ChronicleValues::occurred($occurred); $note=ChronicleValues::text($note,300);
        $result=$this->change(function(array &$data) use ($actor,$id,$member,$state,$occurred,$note,$source): array {
            foreach ($data['goals'] as &$goal) {
                if ($goal['id']!==$id) continue;
                $this->allowed($actor,$goal,'community.write');
                if ($source!=='manual') { $this->policy->require($actor,'access.manage'); }
                if (!in_array($member,$goal['member_ids'],true)) { throw new \InvalidArgumentException('Mitglied gehört nicht zum festen Zielkreis.'); }
                if ($goal['completed_at']!==null) { return ['message'=>'Abschluss bereits festgehalten; der Mitgliederkreis bleibt erhalten.']; }
                $proof=['state'=>$state,'source'=>$source,'occurred_at'=>$occurred,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'note'=>$note,'actor_id'=>$actor->id];
                if (array_diff_assoc(array_diff_key($proof,['observed_at'=>true]),array_diff_key($goal['evidence'][$member],['observed_at'=>true]))) {
                    $goal['evidence'][$member]=$proof; $goal['history'][]=['member_id'=>$member,...$proof]; $goal['history']=array_slice($goal['history'],-64);
                }
                if (count(array_filter($goal['evidence'],fn($proof)=>$proof['state']==='achieved'))===count($goal['member_ids'])) {
                    $goal['completed_at']=gmdate('Y-m-d\TH:i:s\Z'); $sources=array_unique(array_column($goal['evidence'],'source'));
                    $times=array_column($goal['evidence'],'occurred_at');
                    $goal['event']=['id'=>'challenge-'.$goal['id'],'revision'=>1,'title'=>$goal['title'],'description'=>'Gemeinsames Sammelziel: Alle festgelegten Mitglieder haben den Nachweis. Dies belegt keine gleichzeitige Spielsitzung.',
                        'game_id'=>$goal['game_id'],'instance_id'=>$goal['instance_id'],'campaign_id'=>$goal['campaign_id'],'campaign_label'=>$goal['campaign_label'],
                        'member_ids'=>$goal['member_ids'],'member_names'=>(object)$goal['member_names'],'badge_id'=>ChronicleValues::nameId('badge-',$goal['badge']),
                        'badge_label'=>$goal['badge'],'source'=>in_array('steam-import',$sources,true) ? 'steam-import' : (count($sources)===1 && $sources[0]==='verified-game' ? 'verified-game' : 'manual'),
                        'occurred_at'=>in_array('',$times,true) ? '' : max($times),'actor_id'=>$actor->id,'challenge_id'=>$id,'rule_version'=>$goal['rule_version'],
                        'definition'=>'all-members','evidence'=>(object)$goal['evidence'],'observed_at'=>$goal['completed_at']];
                }
                return ['message'=>'Nachweis gespeichert.'];
            }
            throw new \InvalidArgumentException('Unbekanntes Sammelziel.');
        });
        $this->reconcile(); return $result;
    }
    public function withdraw(Principal $actor,string $id,string $reason): void
    {
        $this->policy->require($actor,'access.manage'); $reason=ChronicleValues::text($reason,300,true);
        $this->change(function(array &$data) use ($actor,$id,$reason) {
            foreach ($data['goals'] as &$goal) {
                if ($goal['id']!==$id) continue;
                if (!isset($goal['event'])) { throw new \DomainException('Es gibt noch keinen Abschluss zum Zurückziehen.'); }
                if ($goal['withdrawn']) { throw new \DomainException('Dieser Abschluss wurde bereits zurückgezogen. Für eine neue Regel ein neues Sammelziel anlegen.'); }
                $goal['withdrawn']=true; $goal['withdrawal']=['reason'=>$reason,'actor_id'=>$actor->id]; return;
            }
            throw new \InvalidArgumentException('Unbekanntes Sammelziel.');
        });
        $this->reconcile();
    }
    /** Stable event snapshots make interrupted local projection repeatable without duplicate awards/scenes. */
    public function reconcile(): void
    {
        $hash=is_file($this->path()) ? hash_file('sha256',$this->path()) : 'empty';
        if ($hash===$this->reconciledHash) return;
        foreach ($this->read()['goals'] as $goal) {
            if (!isset($goal['event'])) continue;
            $event=json_decode(json_encode($goal['event'],JSON_THROW_ON_ERROR),flags:JSON_THROW_ON_ERROR);
            $this->ledger->record($event);
            if ($goal['withdrawn']) {
                $correction=clone $event; $correction->revision=2; $correction->removed=true;
                $correction->correction_reason=$goal['withdrawal']['reason']; $correction->actor_id=$goal['withdrawal']['actor_id'];
                $this->ledger->record($correction);
            }
        }
        $this->reconciledHash=$hash;
    }
}
