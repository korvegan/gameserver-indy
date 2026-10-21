<?php
declare(strict_types=1);
namespace App\Instance;
use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Backup\BackupFiles;
use App\Backup\WorldBackups;
use App\Community\ChronicleValues;
use App\Game\GameCatalog;
use App\Job\WorkerClaim;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** World folders belong to one process target. No duplicate executable profiles. */
final class Worlds
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly InstanceRegistry $registry,private readonly InstanceConfiguration $configuration,
        private readonly WorldBinding $binding,private readonly ProcessObservation $observation,private readonly AccessPolicy $policy,
        private readonly GameCatalog $games,private readonly NativePaths $paths,private readonly InstanceMutation $mutation,
        private readonly WorldBackups $backups,private readonly BackupFiles $files,private readonly WorkerClaim $claim,private readonly \App\Runtime\RuntimeLifecycle $lifecycle) {}
    private function path(string $id): string { return $this->runtime->directory.'/data/worlds/'.$id.'.json'; }
    private function rows(string $id): array { return is_file($this->path($id)) ? json_decode(PrivateFiles::read($this->path($id)),true,flags:JSON_THROW_ON_ERROR) : []; }
    private function current(string $id,array $status): array
    {
        $profile=$this->registry->get($id); $bytes=PrivateFiles::read($this->registry->configPath($profile));
        $config=$this->games->adapter($profile->game)->decode($bytes); $binding=$this->binding->resolve($profile,$config,$status['user_profile'] ?? null);
        $key='world-'.substr(hash('sha256',$this->paths->identity($binding['world'] ?? $binding['save_id'])),0,32);
        return ['id'=>$key,'name'=>'Bestandswelt','path'=>$binding['world'],'save_directory'=>$config->saveDirectory ?? null,'save_id'=>$binding['save_id'],'origin'=>'existing','revision'=>hash('sha256',$bytes)];
    }
    public function view(Principal $actor,string $id): array
    {
        $this->policy->require($actor,'backups.manage',$id); $profile=$this->registry->get($id); $current=$this->current($id,$this->observation->view($id));
        $rows=$this->rows($id); $current=[...($rows[$current['id']] ?? []),...$current,'name'=>$rows[$current['id']]['name'] ?? $current['name']]; $rows[$current['id']]=$current;
        return ['current'=>$current,'worlds'=>array_values($rows),'can_change'=>$profile->game==='enshrouded',
            'message'=>$profile->game==='aska' ? 'ASKA-Bestandswelt bleibt an den externen Starter gebunden. Freier Weltwechsel ist hier gesperrt.' : 'Neue Welt oder geprüfte Kopie vorbereiten; Aktivieren erfolgt separat bei gestopptem Server.'];
    }
    public static function validate(?\stdClass $body): void
    {
        if (!$body || array_diff(array_keys(get_object_vars($body)),['action','name','world_id','revision','confirmation'])
            || !in_array($body->action ?? null,['new','clone','switch'],true) || !is_string($body->revision ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$body->revision)
            || ($body->confirmation ?? '')!=='WELTWECHSEL') { throw new \InvalidArgumentException('Weltaktion, aktueller Konfigurationsstand und ausdrückliche Bestätigung erforderlich.'); }
        if ($body->action==='switch') { if (!GameCatalog::identifier($body->world_id ?? null)) throw new \InvalidArgumentException('Registrierte Welt wählen.'); }
        else { ChronicleValues::text($body->name ?? '',100,true); }
    }
    public function execute(Principal $actor,string $id,\stdClass $body): array
    {
        self::validate($body);
        if ($this->claim->forServer($id)===null) { throw new \LogicException('Weltänderung benötigt exklusiven Workerauftrag.'); }
        return $this->mutation->stopped($actor,$id,'backups.manage',function(array $status) use ($actor,$id,$body): array {
            $profile=$this->registry->get($id); if ($profile->game!=='enshrouded') { throw new \DomainException('ASKA-Starter und Save-ID sind fest gebunden; freier Weltwechsel ist gesperrt.'); }
            $current=$this->current($id,$status); if ($body->revision!==$current['revision']) { throw new \DomainException('Konfiguration geändert. Weltansicht neu laden.'); }
            $rows=$this->rows($id); $rows[$current['id']] ??= $current;
            if ($body->action==='switch' && !isset($rows[$body->world_id])) { throw new \InvalidArgumentException('Unbekannte Welt.'); }
            if ($body->action==='switch' && $body->world_id===$current['id']) { return ['message'=>'Diese Welt ist bereits ausgewählt.']; }
            $backup=$this->backups->worldCheckpoint($actor,$id); $guard=function() use ($actor,$id): void {
                $this->lifecycle->requireClaim($this->claim->forServer($id)); $this->policy->require($actor,'backups.manage',$id);
            };
            if ($body->action==='switch') {
                $target=$rows[$body->world_id]; $this->files->native([$target['path']],$target['path']); $this->files->plain($target['path']);
                if (!is_dir($target['path'])) { throw new \DomainException('Registrierter Weltordner fehlt.'); }
            } else {
                $root=$this->files->plain(rtrim($this->paths->local($profile->server_directory),'/\\').'/indy-worlds');
                $this->files->native([dirname($root)]); PrivateFiles::directory($root); $path=$root.'/'.bin2hex(random_bytes(16));
                if ($body->action==='clone') { $this->backups->copyWorld($id,$backup['backup_id'],$path,$guard); }
                else { PrivateFiles::directory($path); }
                $worldId='world-'.substr(hash('sha256',$this->paths->identity($path)),0,32);
                $target=['id'=>$worldId,'name'=>ChronicleValues::text($body->name,100,true),'path'=>$path,'save_directory'=>'./indy-worlds/'.basename($path),'save_id'=>'','origin'=>$body->action,
                    'created_at'=>gmdate('c'),'source_backup'=>$backup['backup_id']]; $rows[$worldId]=$target;
            }
            PrivateFiles::directory(dirname($this->path($id))); PrivateFiles::write($this->path($id),json_encode($rows,JSON_THROW_ON_ERROR));
            if ($body->action==='switch') {
                $adapter=$this->games->adapter($profile->game); $config=$adapter->decode(PrivateFiles::read($this->registry->configPath($profile)));
                $config->saveDirectory=$target['save_directory'];
                $this->configuration->save($actor,$id,$config,$body->revision);
            }
            return ['message'=>$body->action==='switch' ? 'Welt ausgewählt. Start erfolgt ausdrücklich; Bestandswelt und angeheftete Sicherung bleiben erhalten.' : 'Separate Welt vorbereitet. Sie ist noch nicht aktiviert.','world_id'=>$target['id'],'backup_id'=>$backup['backup_id']];
        });
    }
}
