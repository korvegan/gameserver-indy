<?php
declare(strict_types=1);
namespace App\Integration;
use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Backup\BackupFiles;
use App\Backup\WorldBackups;
use App\Game\GameCatalog;
use App\Instance\InstanceConfiguration;
use App\Instance\InstanceMutation;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Storage\PrivateFiles;

/** Steam-client installs use a deliberate manual download step, bounded by verified checkpoints. */
final class UpdateAssistant
{
    public function __construct(private readonly UpdateSessions $sessions,private readonly InstanceRegistry $registry,private readonly InstanceConfiguration $configuration,
        private readonly InstanceMutation $mutation,private readonly WorldBackups $backups,private readonly SteamInstallation $steam,
        private readonly AccessPolicy $policy,private readonly GameCatalog $games,private readonly BackupFiles $files,private readonly NativePaths $paths,private readonly \App\Job\WorkerClaim $claim) {}
    public static function validate(string $operation,?\stdClass $body): void
    {
        $fields=$operation==='update_prepare' ? ['revision'] : ['session_id','acknowledge_changes','review_revision'];
        $actual=$body ? array_keys(get_object_vars($body)) : []; sort($actual); sort($fields);
        if ($actual!==$fields || $operation==='update_prepare' && (!is_string($body->revision) || !preg_match('/^[a-f0-9]{64}$/D',$body->revision))
            || $operation!=='update_prepare' && (!is_string($body->session_id) || !preg_match('/^[a-f0-9]{32}$/D',$body->session_id) || !is_bool($body->acknowledge_changes)
                || !is_string($body->review_revision) || !preg_match('/^[a-f0-9]{64}$/D',$body->review_revision))) { throw new \InvalidArgumentException('Aktuellen Konfigurations-/Assistentenstand ausdrücklich wählen.'); }
    }
    private function comparison(string $id,\stdClass $session): array
    {
        $profile=$this->registry->get($id); $adapter=$this->games->adapter($profile->game);
        $before=get_object_vars($adapter->decode(PrivateFiles::read($this->sessions->path($id).'/before.config',BackupFiles::CONFIG_LIMIT)));
        $bytes=PrivateFiles::read($this->registry->configPath($profile),BackupFiles::CONFIG_LIMIT); $after=get_object_vars($adapter->decode($bytes));
        $changed=[]; foreach ($after as $key=>$value) if (array_key_exists($key,$before) && json_encode($before[$key],JSON_THROW_ON_ERROR)!==json_encode($value,JSON_THROW_ON_ERROR)) $changed[]=$key;
        $external=[]; $hashes=[]; foreach (get_object_vars($session->external) as $path=>$hash) {
            $hashes[$path]=is_file($path) ? $this->files->digest($path) : null; if ($hashes[$path]!==$hash) $external[]=basename($path);
        }
        return ['added'=>array_values(array_diff(array_keys($after),array_keys($before))),'removed'=>array_values(array_diff(array_keys($before),array_keys($after))),
            'changed'=>$changed,'external_changed'=>$external,'values'=>'redacted','revision'=>hash('sha256',json_encode([hash('sha256',$bytes),$hashes],JSON_THROW_ON_ERROR))];
    }
    public function view(Principal $actor,string $id): array
    {
        $this->policy->require($actor,'backups.manage',$id); $this->registry->get($id); $session=$this->sessions->read($id); $steam=null; $error=null; $diff=null;
        try { $steam=$this->steam->read($id); } catch (\Throwable) { $error='Steam-Installationsstand ist nicht eindeutig lesbar.'; }
        if ($session) { try { $diff=$this->comparison($id,$session); } catch (\Throwable) { $error='Konfiguration oder gesicherter Vergleichsstand nicht lesbar. Keine Freigabe für einen Start.'; } }
        return ['session'=>$session ? ['id'=>$session->id,'state'=>$session->state,'prepared_at'=>$session->prepared_at,'before_build'=>$session->before_build,
            'backup_id'=>$session->backup_id,'message'=>$session->message ?? null] : null,'installed'=>$steam,'configuration_revision'=>$this->configuration->view($actor,$id)['revision'],
            'diff'=>$diff,'error'=>$error,'method'=>'steam_client_manual'];
    }
    public function execute(Principal $actor,string $id,string $operation,\stdClass $body): array
    {
        self::validate($operation,$body);
        if ($this->claim->forServer($id)===null) { throw new \LogicException('Update-Assistent benötigt exklusiven Workerauftrag.'); }
        return $this->mutation->stopped($actor,$id,'backups.manage',function(array $status) use ($actor,$id,$operation,$body): array {
            $profile=$this->registry->get($id); $steam=$this->steam->read($id);
            if (!$steam['complete'] || $steam['busy']) { throw new \DomainException('Steam hat die Installation noch nicht vollständig abgeschlossen. Assistent bleibt gesperrt.'); }
            if ($operation==='update_prepare') {
                $bytes=PrivateFiles::read($this->registry->configPath($profile),BackupFiles::CONFIG_LIMIT);
                if (hash('sha256',$bytes)!==$body->revision) { throw new \DomainException('Konfiguration geändert. Updateansicht neu laden.'); }
                $backup=$this->backups->worldCheckpoint($actor,$id); $external=[];
                if (hash('sha256',PrivateFiles::read($this->registry->configPath($profile),BackupFiles::CONFIG_LIMIT))!==$body->revision) { throw new \DomainException('Konfiguration während der Vorbereitung geändert. Sicherung bleibt erhalten; Assistent erneut vorbereiten.'); }
                foreach (array_filter([$profile->launcher_script ?? null]) as $path) { $local=$this->paths->local($path); $external[$local]=$this->files->digest($local); }
                $adapter=$this->games->adapter($profile->game); $config=$adapter->decode($bytes);
                if (($config->{'custom game preset path'} ?? '')!=='') { $path=$this->paths->local($config->{'custom game preset path'}); $external[$path]=$this->files->digest($path); }
                PrivateFiles::directory($this->sessions->path($id)); PrivateFiles::write($this->sessions->path($id).'/before.config',$bytes);
                $this->sessions->store($id,(object)['id'=>bin2hex(random_bytes(16)),'state'=>'waiting_steam','prepared_at'=>gmdate('c'),
                    'before_build'=>$steam['build'],'app_id'=>$steam['app_id'],'branch'=>$steam['branch'],'backup_id'=>$backup['backup_id'],
                    'external'=>(object)$external,'directory'=>$this->paths->identity($profile->server_directory),'config_path'=>$this->paths->identity($this->registry->configPath($profile))]);
                return ['message'=>'Sicherung bestätigt. Jetzt Dedicated Server in Steam aktualisieren und hier Abschluss prüfen. Spielserver bleibt gestoppt.'];
            }
            $session=$this->sessions->read($id);
            if (!$session || $session->state!=='waiting_steam' || $session->id!==$body->session_id) { throw new \DomainException('Kein passender offener Updatevorgang.'); }
            if ($session->app_id!==$steam['app_id'] || $session->branch!==$steam['branch'] || $session->directory!==$this->paths->identity($profile->server_directory)
                || $session->config_path!==$this->paths->identity($this->registry->configPath($profile))) { throw new \DomainException('Installation oder Branch geändert. Updatevorgang gehört zu einem anderen Ziel.'); }
            $diff=$this->comparison($id,$session); $changed=array_filter(array_intersect_key($diff,array_flip(['added','removed','changed','external_changed'])));
            if ($diff['revision']!==$body->review_revision) { throw new \DomainException('Vergleichsstand geändert. Updateansicht neu laden und erneut bestätigen.'); }
            if ($changed && !$body->acknowledge_changes) { throw new \DomainException('Konfigurations-/Starteränderungen zuerst ansehen und ausdrücklich bestätigen. Es wurde nichts überschrieben.'); }
            if ($operation==='update_finish') {
                if ($steam['build']===$session->before_build) { throw new \DomainException('Kein geänderter Steam-Build bestätigt. Steam abschließen oder Vorgang ausdrücklich beenden.'); }
                $adapter=$this->games->adapter($profile->game); $adapter->validate($adapter->decode(PrivateFiles::read($this->registry->configPath($profile),BackupFiles::CONFIG_LIMIT)));
            }
            $session->state=$operation==='update_abort' ? 'cancelled' : 'checked'; $session->after_build=$steam['build']; $session->finished_at=gmdate('c');
            $session->message=$operation==='update_abort' ? 'Assistent bewusst beendet. Das ist kein Programm-/Welt-Rollback.' : 'Steam-Build und Konfiguration geprüft. Jetzt ausdrücklich starten und Spielbereitschaft sowie Beitritt prüfen.';
            $this->sessions->store($id,$session); return ['message'=>$session->message,'config_diff'=>$diff];
        });
    }
}
