<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Access\AccessSnapshot;
use App\Access\AccessStore;
use App\Community\ChronicleSnapshot;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Storage\PrivateFiles;
use App\WhatsApp\WhatsAppSnapshot;

/** Offline legacy administration package. No activation, world writes or container/session copies. */
final class StateSnapshot
{
    public function __construct(private readonly NativePaths $paths,private readonly InstanceRegistry $registry,
        private readonly AccessSnapshot $access,private readonly ChronicleSnapshot $chronicle,private readonly WhatsAppSnapshot $whatsapp) {}

    public function copy(string $source,string $destination,int $port=8765): array
    {
        if (PHP_OS_FAMILY!=='Linux') { throw new \DomainException('Die erste vollständige Datenübernahme unter WSL/Linux ausführen.'); }
        $this->paths->absolute($source); $this->paths->absolute($destination);
        $source=$this->paths->local($source); $destination=$this->paths->local($destination);
        if (!is_dir($source) || is_link($source) || !is_dir($source.'/data') || is_link($source.'/data')
            || file_exists($source.'/.indy-managed-state') || file_exists($source.'/slice.sqlite')
            || !is_dir(dirname($destination)) || file_exists($destination) || is_link($destination)
            || $this->paths->contains($source,$destination) || $this->paths->contains($destination,$source)) {
            throw new \DomainException('Vollständige Quelle und neuen getrennten privaten Paketordner verwenden.');
        }
        $source=realpath($source);
        $original=PrivateFiles::read($source.'/manager.local.json');
        $configuration=$this->registry->validate(PrivateFiles::object($original));
        if (!property_exists(PrivateFiles::object($original),'servers')) { throw new \DomainException('Ausdrückliche Originalprofile erforderlich.'); }
        $accessSettings=AccessStore::configuration(json_decode(PrivateFiles::read($source.'/data/access-settings.json',16384),true,flags:JSON_THROW_ON_ERROR));
        $ports=[$port,$accessSettings['port']];
        if ($ports[0] === $ports[1] || min($ports)<1024 || max($ports)>65535) { throw new \DomainException('Getrennte gültige Originalports erforderlich.'); }
        $lock=$source.'/.indy-controller.lock';
        if (is_link($lock)) { throw new \DomainException('Verknüpfte Controllersperre abgewiesen.'); }
        $lease=fopen($lock,'c+b');
        if ($lease === false || !flock($lease,LOCK_EX|LOCK_NB)) {
            if (is_resource($lease)) { fclose($lease); }
            throw new \DomainException('Die ursprüngliche Verwaltung arbeitet noch.');
        }
        $listeners=[]; $created=false;
        try {
            // Hold both original ports as well: an older Python process may not yet own the lease.
            foreach ($ports as $number) {
                $socket=@stream_socket_server('tcp://127.0.0.1:'.$number,$errno,$error);
                if ($socket === false) { throw new \DomainException('Ursprüngliche Verwaltung zuerst geordnet beenden.'); }
                $listeners[]=$socket;
            }
            $excluded=['data/whatsapp/sessions'];
            foreach ($configuration->servers as $profile) {
                $relative='data'.($profile->id==='enshrouded' ? '' : '/servers/'.$profile->id).'/logs';
                $excluded[]=$relative;
                if (($profile->launcher_script ?? '') !== '' && ($profile->diagnostic_directory ?? '') === '') {
                    $profile->diagnostic_directory=$source.'/'.$relative;
                }
            }
            $before=$this->scan($source.'/data','data',$excluded);
            $required=536870912;
            foreach (array_keys($before) as $relative) { $required+=filesize($source.'/'.$relative); }
            foreach (['access.sqlite3','platform.sqlite3','whatsapp/deliveries.sqlite3'] as $name) {
                if (!is_file($source.'/data/'.$name)) { throw new \DomainException('Vollständige Originaldatenbanken erforderlich.'); }
                $required+=3*filesize($source.'/data/'.$name);
            }
            $free=disk_free_space(dirname($destination));
            if (!is_float($free) || $free<$required) { throw new \DomainException('Zu wenig freier Speicher für vollständiges Paket und Reserve.'); }
            if (!mkdir($destination,0700)) { throw new \DomainException('Privates Paket nicht anlegbar.'); } $created=true;
            PrivateFiles::directory($destination.'/state'); PrivateFiles::directory($destination.'/state/data'); PrivateFiles::directory($destination.'/evidence');
            $databaseNames=['data/access.sqlite3','data/platform.sqlite3','data/whatsapp/deliveries.sqlite3'];
            foreach ($before as $relative=>$hash) {
                if ($this->databaseFile($relative,$databaseNames)) { continue; }
                $this->file($source.'/'.$relative,$destination.'/state/'.$relative,$hash);
            }
            $access=$this->access->copy($source.'/data',$destination.'/evidence/access');
            $chronicle=$this->chronicle->copy($source.'/data/platform.sqlite3',$destination.'/evidence/chronicle');
            $whatsapp=$this->whatsapp->copy($source.'/data/whatsapp',$destination.'/evidence/whatsapp');
            foreach (['access.sqlite3','access-settings.json','session-key'] as $name) {
                $this->file($destination.'/evidence/access/'.$name,$destination.'/state/data/'.$name);
            }
            $this->file($destination.'/evidence/chronicle/platform.sqlite3',$destination.'/state/data/platform.sqlite3');
            foreach (['deliveries.sqlite3','settings.json'] as $name) {
                $this->file($destination.'/evidence/whatsapp/'.$name,$destination.'/state/data/whatsapp/'.$name);
            }
            $settings=PrivateFiles::object(PrivateFiles::read($destination.'/state/data/whatsapp/settings.json',65536));
            $credentials=$destination.'/state/data/whatsapp/credentials.json';
            if (is_file($credentials) || $settings->enabled || $settings->group_id!=='') {
                $value=PrivateFiles::object(PrivateFiles::read($credentials,4096));
                if (!is_string($value->api_key ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$value->api_key)) { throw new \DomainException('Privater WhatsApp-Zugang ist unvollständig.'); }
            }
            $configuration->schema_version=2;
            PrivateFiles::write($destination.'/state/manager.local.json',PrivateFiles::json($configuration));
            PrivateFiles::write($destination.'/evidence/manager.local.json',$original);
            $configurations=[]; PrivateFiles::directory($destination.'/evidence/configurations');
            foreach ($configuration->servers as $profile) {
                $path=$this->registry->configPath($profile); $bytes=PrivateFiles::read($path);
                if (is_link($path)) { throw new \DomainException('Verknüpfte Originalkonfiguration abgewiesen.'); }
                PrivateFiles::write($destination.'/evidence/configurations/'.$profile->id.'.bin',$bytes);
                $configurations[$profile->id]=['path'=>$path,'sha256'=>hash('sha256',$bytes)];
            }
            if ($before !== $this->scan($source.'/data','data',$excluded) || PrivateFiles::read($source.'/manager.local.json') !== $original) {
                throw new \DomainException('Originalbestand wurde während der Kopie geändert. Paket verworfen.');
            }
            foreach ($configurations as $evidence) {
                if (!hash_equals($evidence['sha256'],hash_file('sha256',$evidence['path']))) { throw new \DomainException('Originalkonfiguration wurde während der Kopie geändert.'); }
            }
            $hashes=$this->scan($destination,'',[]);
            $manifest=(object) ['format'=>'gameserver-indy-state-snapshot','version'=>1,'source'=>$source,'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'hashes'=>(object) $hashes,'configurations'=>(object) $configurations,'excluded'=>$excluded,
                'access'=>$access,'chronicle'=>$chronicle,'whatsapp'=>$whatsapp];
            PrivateFiles::write($destination.'/manifest.json',PrivateFiles::json($manifest));
            // Deliberately no production marker, runtime tools, listener, worker or activation pointer.
            return ['files'=>count($hashes),'profiles'=>count($configuration->servers),'verified'=>true];
        } catch (\Throwable $error) {
            if ($created) { $this->remove($destination); }
            throw $error;
        } finally {
            foreach ($listeners as $listener) { fclose($listener); }
            flock($lease,LOCK_UN); fclose($lease);
        }
    }

    private function databaseFile(string $relative,array $names): bool
    {
        foreach ($names as $name) { if (in_array($relative,[$name,$name.'-wal',$name.'-shm',$name.'-journal'],true)) { return true; } }
        return false;
    }
    private function scan(string $directory,string $prefix,array $excluded): array
    {
        if (is_link($directory)) { throw new \DomainException('Verknüpfte Datenordner abgewiesen.'); }
        $entries=scandir($directory);
        if ($entries===false) { throw new \DomainException('Datenbestand nicht vollständig lesbar.'); }
        $result=[];
        foreach ($entries as $name) {
            if ($name==='.' || $name==='..') { continue; }
            $relative=($prefix==='' ? '' : $prefix.'/').$name;
            if (in_array($relative,$excluded,true)) { continue; }
            $path=$directory.'/'.$name;
            if (is_link($path)) { throw new \DomainException('Verknüpfte Verwaltungsdatei abgewiesen.'); }
            if (is_dir($path)) {
                $result += $this->scan($path,$relative,$excluded);
                if (count($result)>100000) { throw new \DomainException('Zu viele Verwaltungsdateien für ein vollständiges Paket.'); }
                continue;
            }
            if (!is_file($path)) { throw new \DomainException('Unbekannter Verwaltungsdateityp.'); }
            $info=lstat($path);
            if ($info===false || ($info['mode'] & 0170000)!==0100000 || $info['nlink']!==1) { throw new \DomainException('Verwaltungsdatei ist keine gewöhnliche unabhängige Datei.'); }
            // SQLite may update its shared-memory bookkeeping even on a read-only snapshot.
            if (str_ends_with($relative,'.sqlite3-shm')) { continue; }
            $hash=hash_file('sha256',$path);
            if (!is_string($hash)) { throw new \DomainException('Verwaltungsdatei nicht vollständig lesbar.'); }
            $result[$relative]=$hash;
            if (count($result)>100000) { throw new \DomainException('Zu viele Verwaltungsdateien für ein vollständiges Paket.'); }
        }
        ksort($result,SORT_STRING); return $result;
    }
    private function file(string $source,string $target,?string $expected=null): void
    {
        PrivateFiles::directory(dirname($target));
        if (is_link($source) || !is_file($source) || !copy($source,$target)) { throw new \DomainException('Verwaltungskopie unvollständig.'); }
        chmod($target,0600);
        $expected ??= hash_file('sha256',$source);
        if (!hash_equals($expected,hash_file('sha256',$target))) { throw new \DomainException('Prüfsumme der Verwaltungskopie stimmt nicht.'); }
    }
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) { if ($name!=='.' && $name!=='..') { $this->remove($path.'/'.$name); } }
            rmdir($path);
        } else { unlink($path); }
    }
}
