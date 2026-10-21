<?php
declare(strict_types=1);
namespace App\Integration;
use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Community\ChronicleValues;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Immutable reference manifests; no access to player files and no download authority. */
final class ModProfiles
{
    private const MAX_BYTES=33554432;
    public function __construct(private readonly SliceRuntime $runtime,private readonly ModCatalog $catalog,private readonly AccessPolicy $policy) {}
    private function path(): string { return $this->runtime->directory.'/data/mod-profiles.json'; }
    private function read(): array
    {
        $this->runtime->assertInitialized();
        return is_file($this->path()) ? json_decode(PrivateFiles::read($this->path(),self::MAX_BYTES),true,flags:JSON_THROW_ON_ERROR) : ['version'=>1,'games'=>[]];
    }
    private function change(Principal $actor,string $game,callable $callback): array
    {
        $this->catalog->view($actor,$game);
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('mod-profiles'); $lock->acquire(true);
        try {
            $data=$this->read(); $rows=$data['games'][$game] ?? []; $result=$callback($rows); $data['games'][$game]=$rows;
            $bytes=json_encode($data,JSON_THROW_ON_ERROR);
            if (strlen($bytes)>self::MAX_BYTES) { throw new \DomainException('Modprofil-Historie erreicht 32 MiB. Bestehende Revisionen bleiben lesbar; keine neue Änderung gespeichert.'); }
            PrivateFiles::directory(dirname($this->path())); PrivateFiles::write($this->path(),$bytes); return $result;
        } finally { $lock->release(); }
    }
    public function view(Principal $actor,string $game): array
    {
        $this->catalog->view($actor,$game); $profiles=[];
        foreach (array_reverse($this->read()['games'][$game] ?? []) as $row) {
            $receipt=$row['receipts'][$actor->id] ?? null;
            if ($receipt) { $receipt['fresh']=time()-$receipt['checked_at']<86400; }
            $profiles[]=['manifest'=>$row['manifest'],'sha256'=>$row['sha256'],'withdrawn_at'=>$row['withdrawn_at'],'receipt'=>$receipt,
                'manual_confirmations'=>count(array_filter($row['receipts'],fn($r)=>$r['status']==='confirmed' && time()-$r['checked_at']<86400))];
        }
        return ['profiles'=>$profiles,'verification'=>'manual_only','fresh_for_seconds'=>86400];
    }
    public function publish(Principal $actor,string $game,\stdClass $body): array
    {
        $this->policy->require($actor,'catalog.write');
        if (array_diff(array_keys(get_object_vars($body)),['name','game_version','loader','items']) || !is_array($body->items ?? null)
            || !array_is_list($body->items) || !$body->items || count($body->items)>100 || array_filter($body->items,fn($id)=>!is_string($id))
            || count(array_unique($body->items))!==count($body->items)) { throw new \InvalidArgumentException('Benanntes Profil mit ausgewählten Mods erstellen.'); }
        $catalog=$this->catalog->view($actor,$game); $items=[];
        $identities=[];
        foreach ($body->items as $id) {
            $item=$catalog['items'][$id] ?? throw new \InvalidArgumentException('Unbekannter Modstand.');
            if ($item['version']==='' || !$item['file_id'] || !$item['sha256'] || $item['loader']==='') { throw new \DomainException('Vor Freigabe Version, Dateikennung, SHA-256 und Loader jedes Mods eintragen.'); }
            if (array_diff($item['dependencies'],$body->items)) { throw new \DomainException('Alle angegebenen Abhängigkeiten müssen im Profil enthalten sein.'); }
            $identity=$item['source'].'|'.$item['game_key'].'|'.$item['mod_id'];
            if (in_array($identity,$identities,true)) { throw new \DomainException('Pro Mod genau einen Dateistand wählen.'); } $identities[]=$identity;
            $items[]=$item;
        }
        usort($items,fn($a,$b)=>[$a['order'],$a['id']]<=>[$b['order'],$b['id']]);
        $name=ChronicleValues::text($body->name ?? '',100,true); $version=ChronicleValues::text($body->game_version ?? '',100,true);
        $loader=ChronicleValues::text($body->loader ?? '',100,true);
        return $this->change($actor,$game,function(array &$rows) use ($actor,$game,$name,$version,$loader,$items): array {
            $id='profile-'.bin2hex(random_bytes(16));
            $manifest=['format'=>'indy-mod-profile','format_version'=>1,'id'=>$id,'revision'=>count($rows)+1,'game'=>$game,
                'name'=>$name,'game_version'=>$version,'loader'=>$loader,'published_at'=>gmdate('c'),'download_mode'=>'original_references_only',
                'installation'=>'manual','items'=>$items];
            $hash=hash('sha256',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $rows[$id]=['manifest'=>$manifest,'sha256'=>$hash,'withdrawn_at'=>null,'receipts'=>[]];
            return ['message'=>'Profilrevision veröffentlicht. Spieler bestätigen den eigenen Abgleich; keine Clientdateien wurden geändert.','id'=>$id];
        });
    }
    public function action(Principal $actor,string $game,string $id,\stdClass $body): array
    {
        return $this->change($actor,$game,function(array &$rows) use ($actor,$id,$body): array {
            if (!isset($rows[$id])) { throw new \InvalidArgumentException('Unbekanntes Modprofil.'); } $row=&$rows[$id];
            if (($body->action ?? '')==='withdraw') {
                $this->policy->require($actor,'catalog.write'); $row['withdrawn_at'] ??= gmdate('c');
                return ['message'=>'Profilrevision zurückgezogen. Bestehende Installationen bleiben erhalten.'];
            }
            if (($body->action ?? '')!=='confirm' || array_diff(array_keys(get_object_vars($body)),['action','sha256','status'])
                || !in_array($body->status ?? null,['confirmed','different'],true) || ($body->sha256 ?? null)!==$row['sha256'] || $row['withdrawn_at']) {
                throw new \InvalidArgumentException('Aktive Profilrevision und eigenen manuellen Prüfstand bestätigen.');
            }
            $this->policy->require($actor,'catalog.read');
            $row['receipts'][$actor->id]=['type'=>'manual','status'=>$body->status,'checked_at'=>time(),'manifest_sha256'=>$row['sha256']];
            return ['message'=>'Eigene manuelle Rückmeldung gespeichert. Kein automatischer Nachweis der lokalen Dateien oder des Spielbeitritts.'];
        });
    }
    public function export(Principal $actor,string $game,string $id): string
    {
        $this->catalog->view($actor,$game); $row=$this->read()['games'][$game][$id] ?? throw new \InvalidArgumentException('Unbekanntes Modprofil.');
        if ($row['withdrawn_at']) { throw new \DomainException('Zurückgezogene Revision nicht mehr exportieren.'); }
        return json_encode($row['manifest'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }
    public function compare(Principal $actor,string $game,string $id,string $base): array
    {
        $this->catalog->view($actor,$game); $rows=$this->read()['games'][$game] ?? [];
        if (!isset($rows[$id],$rows[$base])) { throw new \InvalidArgumentException('Zwei vorhandene Revisionen wählen.'); }
        $before=$rows[$base]['manifest']; $after=$rows[$id]['manifest'];
        // Stable provider/game/mod identity pairs versioned file IDs across revisions.
        $key=fn($item)=>$item['source'].'|'.$item['game_key'].'|'.$item['mod_id'];
        $a=[]; $b=[]; foreach ($before['items'] as $item) $a[$key($item)]=$item; foreach ($after['items'] as $item) $b[$key($item)]=$item;
        return ['added'=>array_values(array_diff_key($b,$a)),'removed'=>array_values(array_diff_key($a,$b)),
            'changed'=>array_values(array_filter($b,fn($item,$id)=>isset($a[$id]) && $item!==$a[$id],ARRAY_FILTER_USE_BOTH)),
            'game_version_changed'=>$before['game_version']!==$after['game_version'],'loader_changed'=>$before['loader']!==$after['loader']];
    }
}
