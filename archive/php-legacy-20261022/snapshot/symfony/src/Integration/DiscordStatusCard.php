<?php
declare(strict_types=1);
namespace App\Integration;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Runtime\OutcomeUnknown;
use App\Runtime\ProcessObservation;
use Doctrine\DBAL\Connection;

/** One explicitly created message per profile. Only PATCH may be retried automatically. */
final class DiscordStatusCard
{
    public function __construct(private readonly Discord $discord, private readonly DiscordTransport $transport,
        private readonly Connection $db, private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly Connections $connections, private readonly ProcessObservation $observation,
        private readonly JobRepository $jobs, private readonly AccessPolicy $policy) {}
    private function row(string $id): array|false
    { return $this->db->fetchAssociative('SELECT * FROM discord_status_cards WHERE server_id=?',[$id]); }
    public function active(string $id): bool
    { $row=$this->row($id); return $row && $row['enabled'] && in_array($row['state'],['active','updating','retry'],true); }
    public function view(Principal $actor,string $id): array
    {
        $this->policy->require($actor,'discord.write',$id); $row=$this->row($id);
        return ['enabled'=>(bool)($row['enabled'] ?? false),'state'=>$row['state'] ?? 'none',
            'message_id'=>$row['remote_id'] ?? null,'updated_at'=>isset($row['updated_at']) ? gmdate('Y-m-d\TH:i:s\Z',(int)$row['updated_at']) : null,
            'error'=>$row['error'] ?? null,'next_try'=>$row['next_try'] ?? null,'generation'=>$row['generation'] ?? 'new'];
    }
    public function disable(Principal $actor,string $id): void
    {
        $this->policy->require($actor,'discord.write',$id);
        $this->db->update('discord_status_cards',['enabled'=>0,'generation'=>bin2hex(random_bytes(16))],['server_id'=>$id]);
    }
    public function due(): array
    {
        return $this->db->fetchFirstColumn("SELECT server_id FROM discord_status_cards WHERE enabled=1 AND remote_id IS NOT NULL
            AND state IN ('active','updating','retry') AND attempted_at<=? AND next_try<=?
            AND (state='retry' OR revision='' OR updated_at<=?)",[time()-60,time(),time()-300]);
    }
    public function nextDue(): int
    {
        $next = $this->db->fetchOne("SELECT MIN(MAX(attempted_at+60,next_try,CASE WHEN state='retry' OR revision='' THEN 0 ELSE updated_at+300 END)) FROM discord_status_cards WHERE enabled=1
            AND remote_id IS NOT NULL AND state IN ('active','updating','retry')");
        return $next === null || $next === false ? time()+900 : (int)$next;
    }
    public function invalidate(?string $id): void
    {
        $this->db->executeStatement("UPDATE discord_status_cards SET revision='' WHERE enabled=1 AND state='active'".($id === null ? '' : ' AND server_id=?'), $id === null ? [] : [$id]);
    }
    public function execute(string $id,bool $create,bool $replace,callable $guard,?string $generation=null): array
    {
        $guard(); $profile=$this->registry->get($id); $row=$this->row($id); $now=time();
        try { $url=$this->discord->webhook(); } catch (\DomainException) { $url=''; }
        if ($url==='') {
            if ($row && !$create) {
                $this->db->update('discord_status_cards',['state'=>'blocked','error'=>'Webhook entfernt oder ungültig. Verbindung zuerst einrichten.'],['server_id'=>$id]);
                return ['message'=>'Statuskarte gesperrt: Webhook fehlt.'];
            }
            throw new \DomainException('Bitte zuerst den Discord-Webhook einrichten.');
        }
        $hash=hash('sha256',$url);
        if ($create) {
            if ($generation!==null && $generation!==($row['generation'] ?? 'new')) {
                return ['message'=>'Kartenauftrag durch eine spätere Änderung aufgehoben. Es wurde keine Nachricht angelegt.'];
            }
            if ($row && !$replace) { throw new \DomainException('Es gibt bereits eine Statuskarte oder einen unklaren Versand. Ersetzen ausdrücklich bestätigen.'); }
            $data=['webhook_hash'=>$hash,'remote_id'=>null,'revision'=>'','state'=>'creating','enabled'=>1,
                'attempted_at'=>$now,'updated_at'=>null,'next_try'=>0,'error'=>null,'generation'=>bin2hex(random_bytes(16))];
            if ($row) { $this->db->update('discord_status_cards',$data,['server_id'=>$id]); }
            else { $this->db->insert('discord_status_cards',['server_id'=>$id,...$data]); }
            $row=['server_id'=>$id,...$data]; // Durable before POST: interrupted creation must never be replayed.
        } else {
            if (!$row || !$row['enabled'] || !in_array($row['state'],['active','updating','retry'],true) || !$row['remote_id']) {
                return ['message'=>'Statuskarte wird nicht automatisch aktualisiert.'];
            }
            if ($row['webhook_hash']!==$hash) {
                $this->db->update('discord_status_cards',['state'=>'blocked','error'=>'Webhook geändert. Eine neue Karte ausdrücklich erstellen.'],['server_id'=>$id]);
                return ['message'=>'Statuskarte gehört zum vorherigen Webhook.'];
            }
            if ((int)$row['next_try']>$now) { return ['message'=>'Discord-Wartezeit läuft noch.']; }
        }
        $status=$this->observation->view($id); $ready=(array)($status['readiness'] ?? []);
        $details=$this->connections->details($id); $schema=$this->games->schema($profile->game);
        $lines=[$schema->label.' · '.$details['server_name'], $ready['label'] ?? 'Status unbekannt',
            $details['public_address'] ? 'Adresse: '.$details['public_address'] : 'Im Spiel nach Servername suchen: '.$details['server_name'],
            'Externer Beitritt ist durch diese Karte nicht geprüft.'];
        // Surface maintenance and backup outcomes without copying job payloads, actors or error texts.
        foreach ($this->jobs->recent([$id]) as $job) {
            if (in_array($job['state'],['queued','running'],true) && in_array($job['operation'],['stop','restart','backup','backup_restore'],true)) {
                $lines[]='Wartungsauftrag: '.$job['operation'].' · '.$job['state']; break;
            }
            if (str_starts_with($job['operation'],'backup') && in_array($job['state'],['failed','interrupted'],true)) {
                $lines[]='Letzte Sicherungsaktion fehlgeschlagen oder unklar. Im Dashboard prüfen.'; break;
            }
        }
        // Fresh timestamp in the message, stable content revision between real changes / 5-minute heartbeats.
        $revision=hash('sha256',json_encode([$lines,$ready['revision'] ?? null],JSON_THROW_ON_ERROR));
        $this->db->update('discord_status_cards',['attempted_at'=>$now],['server_id'=>$id]);
        if (!$create && $row['revision']===$revision && $row['state']==='active' && $now-(int)$row['updated_at']<300) { return ['message'=>'Statuskarte bereits aktuell.']; }
        $lines[]='Stand: '.gmdate('d.m.Y H:i:s',$now).' UTC';
        $message=DiscordWebhook::message(implode("\n",$lines));
        try {
            $guard();
            if (!$create) { $this->db->update('discord_status_cards',['state'=>'updating'],['server_id'=>$id]); }
            $remote=$create ? $this->transport->send($url,$message) : $this->transport->edit($url,$row['remote_id'],$message);
            if (!preg_match('/^[0-9]{1,30}$/D',$remote) || !$create && $remote!==$row['remote_id']) { throw new OutcomeUnknown('Nachrichtenkennung nicht bestätigt.'); }
            $this->db->update('discord_status_cards',['state'=>'active','remote_id'=>$remote,'revision'=>$revision,'updated_at'=>$now,'next_try'=>0,'error'=>null],['server_id'=>$id]);
        } catch (\Throwable $error) {
            $retry=!$create && ($error instanceof DiscordRateLimit || !$error instanceof \DomainException);
            $this->db->update('discord_status_cards',['state'=>$retry ? 'retry' : ($create ? 'uncertain' : 'blocked'),
                'next_try'=>$now+($error instanceof DiscordRateLimit ? $error->seconds : 300),
                'error'=>$retry ? 'Aktualisierung wartet auf Discord; dieselbe Nachricht wird später erneut bearbeitet.'
                    : 'Karte nicht bestätigt oder Nachricht/Webhook entfernt. Im Kanal prüfen und bei Bedarf ausdrücklich ersetzen.'],['server_id'=>$id]);
            throw new \DomainException('Discord-Statuskarte konnte nicht bestätigt werden. Versandstatus im Dashboard prüfen.');
        }
        return ['message'=>'Discord-Statuskarte aktualisiert.'];
    }
}
