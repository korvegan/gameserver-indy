<?php
declare(strict_types=1);
namespace App\Integration;
use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Community\ChronicleValues;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ModCatalog
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly AccessPolicy $policy,
        private readonly GameCatalog $games,private readonly InstanceRegistry $registry,private readonly ModSources $sources) {}
    private function path(): string { return $this->runtime->directory.'/data/mod-catalog.json'; }
    private function read(): array
    {
        $this->runtime->assertInitialized();
        return is_file($this->path()) ? json_decode(PrivateFiles::read($this->path()),true,flags:JSON_THROW_ON_ERROR) : ['version'=>1,'games'=>[]];
    }
    private function game(string $game): void
    {
        if (!in_array($game,array_column($this->games->entries($this->registry->settings()->catalog_games ?? []),'id'),true)) { throw new \InvalidArgumentException('Spiel zuerst im Katalog anlegen.'); }
    }
    public function view(Principal $actor,string $game): array
    {
        $this->policy->require($actor,'catalog.read'); $this->game($game);
        return [...($this->read()['games'][$game] ?? ['bindings'=>[],'items'=>[]]),'sources'=>$this->sources->capabilities($game)];
    }
    public function save(Principal $actor,string $game,\stdClass $body): array
    {
        $this->policy->require($actor,'catalog.write'); $this->game($game);
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('mod-catalog'); $lock->acquire(true);
        try {
            $data=$this->read(); $entry=$data['games'][$game] ?? ['bindings'=>[],'items'=>[]];
            if (($body->action ?? '')==='source') {
                if (array_diff(array_keys(get_object_vars($body)),['action','source','game_key']) || !is_string($body->source ?? null) || !is_string($body->game_key ?? null)) { throw new \InvalidArgumentException('Quelle und Spielkennung angeben.'); }
                $source=$this->sources->source($body->source); $key=$source->gameKey(trim($body->game_key));
                if (isset($entry['bindings'][$source->id()]) && $entry['bindings'][$source->id()]!==$key
                    && array_filter($entry['items'],fn($item)=>$item['source']===$source->id())) { throw new \DomainException('Quelle enthält Mods; deren Spielzuordnung darf nicht nachträglich geändert werden.'); }
                $entry['bindings'][$source->id()]=$key;
            } elseif (($body->action ?? '')==='item') {
                if (array_diff(array_keys(get_object_vars($body)),['action','source','url','name','version','file_id','sha256','loader','target','dependencies','order','notes'])
                    || !is_string($body->source ?? null) || !is_string($body->url ?? null)) { throw new \InvalidArgumentException('Modquelle und Originalseite angeben.'); }
                $key=$entry['bindings'][$body->source] ?? throw new \DomainException('Modquelle zuerst dem Spiel zuordnen.');
                $link=$this->sources->source($body->source)->link($key,$body->url);
                $file=ChronicleValues::text($body->file_id ?? '',100) ?: ($link['file_id'] ?? '');
                if ($link['file_id']!==null && $file!==$link['file_id']) { throw new \InvalidArgumentException('Dateikennung muss zum ausgewählten Quellenlink passen.'); }
                $hash=ChronicleValues::text($body->sha256 ?? '',64);
                if ($hash!=='' && !preg_match('/^[a-f0-9]{64}$/D',$hash)) { throw new \InvalidArgumentException('SHA-256 als 64 kleine Hexzeichen angeben.'); }
                if (!in_array($body->target ?? null,['client','server','both','optional-client'],true) || !is_int($body->order ?? null) || $body->order<0 || $body->order>9999
                    || !is_array($body->dependencies ?? null) || !array_is_list($body->dependencies) || count($body->dependencies)>50
                    || array_filter($body->dependencies,fn($id)=>!GameCatalog::identifier($id))) { throw new \InvalidArgumentException('Ziel, Ladefolge und Abhängigkeitskennungen prüfen.'); }
                $id='mod-'.substr(hash('sha256',$body->source.'|'.$key.'|'.$link['mod_id'].'|'.$file),0,32);
                $entry['items'][$id]=[...$link,'id'=>$id,'source'=>$body->source,'game_key'=>$key,'file_id'=>$file ?: null,
                    'name'=>ChronicleValues::text($body->name ?? '',120,true),'version'=>ChronicleValues::text($body->version ?? '',100),
                    'sha256'=>$hash ?: null,'loader'=>ChronicleValues::text($body->loader ?? '',100),'target'=>$body->target,
                    'dependencies'=>array_values(array_unique($body->dependencies)),'order'=>$body->order,
                    'notes'=>ChronicleValues::text($body->notes ?? '',1000),'metadata_source'=>'manual','availability'=>'not_checked'];
            } else { throw new \InvalidArgumentException('Unbekannte Katalogaktion.'); }
            $data['games'][$game]=$entry; PrivateFiles::directory(dirname($this->path()));
            PrivateFiles::write($this->path(),json_encode($data,JSON_THROW_ON_ERROR));
        } finally { $lock->release(); }
        return ['message'=>'Modkatalog gespeichert. Es wurden keine Dateien heruntergeladen oder installiert.'];
    }
}
