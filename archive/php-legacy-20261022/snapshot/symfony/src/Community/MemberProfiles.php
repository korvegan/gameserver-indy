<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Small invited roster; event payloads retain their own historical names and IDs. */
final class MemberProfiles
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly AccessStore $access) {}
    private function path(): string { return $this->runtime->directory.'/data/community-members.json'; }
    private function read(): array
    {
        $this->runtime->assertInitialized();
        if (!is_file($this->path())) { return []; }
        $data=json_decode(PrivateFiles::read($this->path()),true,flags:JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null)!==1 || !is_array($data['members'] ?? null)) { throw new \DomainException('Mitgliederverzeichnis nicht lesbar.'); }
        return $data['members'];
    }
    private function change(callable $callback): mixed
    {
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('community-members'); $lock->acquire(true);
        try {
            $rows=$this->read(); $result=$callback($rows); PrivateFiles::directory(dirname($this->path()));
            PrivateFiles::write($this->path(),json_encode(['version'=>1,'members'=>$rows],JSON_THROW_ON_ERROR)); return $result;
        } finally { $lock->release(); }
    }
    public function own(Principal $actor): ?array
    {
        $this->access->validate($actor);
        foreach ($this->read() as $member) { if ($member['enabled'] && in_array($actor->id,$member['principals'],true)) { return $member; } }
        return null;
    }
    public function contributes(Principal $actor): bool { return ($this->own($actor)['role'] ?? null)==='writer'; }
    public function view(Principal $actor): array
    {
        $this->access->validate($actor); $owner=$actor->allows('access.manage');
        $rows=array_values(array_filter($this->read(),fn($member)=>$owner || $member['enabled']));
        $own=$this->own($actor); $accounts=[];
        if ($owner) {
            $accounts[]=['id'=>'local-owner','name'=>'Besitzer an diesem PC','enabled'=>true];
            foreach ($this->access->view()['users'] as $user) { $accounts[]=['id'=>$user['id'],'name'=>$user['name'].($user['enabled'] ? '' : ' · Zugang gesperrt'),'enabled'=>$user['enabled']]; }
        }
        return ['members'=>array_map(function($member) use ($owner,$own) {
            unset($member['link_generation']);
            if (!$owner) { unset($member['principals']); }
            if (($own['id'] ?? null)!==$member['id']) { unset($member['steam_id']); }
            return [...$member,'steam_verified'=>isset($member['steam_verified_at']),'steam_data_state'=>'unknown'];
        },$rows),'own'=>$own ? ['id'=>$own['id'],'name'=>$own['name'],'steam_id'=>$own['steam_id'] ?? null,
            'steam_verified_at'=>$own['steam_verified_at'] ?? null] : null,'accounts'=>$accounts];
    }
    public function save(Principal $actor,\stdClass $value): array
    {
        $this->access->validate($actor);
        if (!$actor->allows('access.manage')) { throw new AccessDeniedException('Mitglieder lädt ein Besitzer ein.'); }
        if (array_diff(array_keys(get_object_vars($value)),['id','name','principals','role','enabled']) || !is_array($value->principals ?? null)
            || !array_is_list($value->principals) || count($value->principals)<1 || !in_array($value->role ?? null,['reader','writer'],true)
            || !is_bool($value->enabled ?? null)) { throw new \InvalidArgumentException('Name, persönliche Freigaben und Gemeinschaftsrolle wählen.'); }
        $name=ChronicleValues::text($value->name ?? '',80,true); $known=['local-owner'];
        foreach ($this->access->view()['users'] as $user) { if ($user['enabled']) { $known[]=$user['id']; } }
        if (array_filter($value->principals,fn($id)=>!is_string($id)) || count(array_unique($value->principals))!==count($value->principals)) { throw new \InvalidArgumentException('Persönliche Identitäten eindeutig wählen.'); }
        return $this->change(function(array &$rows) use ($value,$name,$known): array {
            $id=$value->id ?? null; $index=null;
            foreach ($rows as $i=>$member) {
                if ($member['id']===$id) { $index=$i; continue; }
                if (array_intersect($value->principals,$member['principals'])) { throw new \DomainException('Eine persönliche Identität gehört bereits zu einem Mitglied.'); }
            }
            if ($id!==null && $index===null) { throw new \InvalidArgumentException('Unbekanntes Mitglied.'); }
            $allowed=[...$known,...($index!==null ? $rows[$index]['principals'] : [])];
            if (array_diff($value->principals,$allowed)) { throw new \InvalidArgumentException('Neue Zuordnungen benötigen freigegebene Identitäten. Bisherige entzogene Zugänge dürfen erhalten bleiben.'); }
            $id ??= 'member-'.bin2hex(random_bytes(16));
            $member=[...($index!==null ? $rows[$index] : ['link_generation'=>bin2hex(random_bytes(16))]),'id'=>$id,'name'=>$name,'principals'=>$value->principals,'role'=>$value->role,'enabled'=>$value->enabled];
            if ($index===null) { $rows[]=$member; } else { $rows[$index]=$member; }
            return ['message'=>'Mitgliederprofil gespeichert. Historische Momente behalten ihre bisherigen Angaben.','member_id'=>$id];
        });
    }
    public function rename(Principal $actor,string $name): void
    { $this->changeOwn($actor,function(array &$row) use ($name) { $row['name']=ChronicleValues::text($name,80,true); }); }
    public function steam(Principal $actor,?string $steamId,?string $generation=null): void
    {
        if ($steamId!==null && !preg_match('/^[0-9]{17}$/D',$steamId)) { throw new \InvalidArgumentException('Ungültige bestätigte Steam-ID.'); }
        $this->changeOwn($actor,function(array &$row,array $rows) use ($steamId,$generation) {
            if ($generation!==null && $generation!==$row['link_generation']) { throw new \DomainException('Steam-Verknüpfung wurde zwischenzeitlich geändert. Bitte neu beginnen.'); }
            foreach ($rows as $other) { if ($steamId!==null && $other['id']!==$row['id'] && ($other['steam_id'] ?? null)===$steamId) { throw new \DomainException('Dieses Steam-Konto ist bereits einem anderen Mitglied zugeordnet.'); } }
            if ($steamId!==null && isset($row['steam_id']) && $row['steam_id']!==$steamId) { throw new \DomainException('Vor einem Kontowechsel die bisherige Steam-Verknüpfung ausdrücklich trennen.'); }
            unset($row['steam_id'],$row['steam_verified_at']);
            $row['link_generation']=bin2hex(random_bytes(16));
            if ($steamId!==null) { $row['steam_id']=$steamId; $row['steam_verified_at']=gmdate('Y-m-d\TH:i:s\Z'); }
        });
    }
    private function changeOwn(Principal $actor,callable $callback): void
    {
        $this->access->validate($actor);
        $this->change(function(array &$rows) use ($actor,$callback) {
            foreach ($rows as &$member) { if ($member['enabled'] && in_array($actor->id,$member['principals'],true)) { $callback($member,$rows); return; } }
            throw new AccessDeniedException('Zuerst ein eigenes Mitgliederprofil freigeben lassen.');
        });
    }
    public function selected(array $ids): array
    {
        if (!array_is_list($ids) || count($ids)>16 || count(array_unique($ids,SORT_REGULAR))!==count($ids)) { throw new \InvalidArgumentException('Beteiligte eindeutig auswählen.'); }
        $result=[]; $members=$this->read();
        foreach ($ids as $id) {
            $found=false;
            foreach ($members as $row) { if ($row['id']===$id && $row['enabled']) { $result[$id]=$row['name']; $found=true; break; } }
            if (!$found) { throw new \DomainException('Ein ausgewähltes Mitglied ist nicht mehr freigegeben.'); }
        }
        return $result;
    }
}
