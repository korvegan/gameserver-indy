<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Access\AccessStore;
use App\Instance\NativePaths;
use App\Storage\PrivateFiles;
use Symfony\Component\Process\Process;

/** Prepare a new shared state from a verified offline package; never switch the live launcher. */
final class StateInstallation
{
    public function __construct(private readonly StateSnapshot $snapshots,private readonly LegacyLease $lease,
        private readonly ReleasePackage $releases,private readonly NativePaths $paths) {}

    public function install(string $package,string $destination,string $release,string $commit,string $php,string $frankenphp): array
    {
        if (PHP_OS_FAMILY!=='Linux') { throw new \DomainException('Erste Dateninstallation unter WSL/Linux vorbereiten.'); }
        foreach ([$package,$destination,$release,$php,$frankenphp] as $path) { $this->paths->absolute($path); }
        if (!str_starts_with($destination,'/') || !is_dir(dirname($destination)) || file_exists($destination) || is_link($destination)) {
            throw new \DomainException('Neues privates Installationsziel mit vorhandenem Elternordner erforderlich.');
        }
        $destination=realpath(dirname($destination)).'/'.basename($destination);
        $manifest=$this->snapshots->verify($package); $this->releases->verify($release,$commit);
        foreach ([$package,$release,$manifest->source] as $source) {
            if ($this->paths->contains($source,$destination) || $this->paths->contains($destination,$source)) { throw new \DomainException('Getrennte Code-, Paket-, Original- und Datenpfade verwenden.'); }
        }
        foreach ([$php,$frankenphp] as $tool) {
            if (!str_starts_with($tool,'/') || !is_file($tool) || !is_executable($tool)) { throw new \DomainException('Absolute ausführbare private Laufzeitwerkzeuge erforderlich.'); }
        }
        $required=536870912;
        foreach ($manifest->hashes as $relative=>$hash) { if (str_starts_with($relative,'state/')) { $required+=filesize($package.'/'.$relative); } }
        $free=disk_free_space(dirname($destination));
        if (!is_float($free) || $free<$required) { throw new \DomainException('Zu wenig Speicher für Dateninstallation und Reserve.'); }
        return $this->lease->run($manifest->source,$manifest->source_port,function () use($package,$destination,$release,$commit,$php,$frankenphp,$manifest): array {
            $this->snapshots->assertCurrent($manifest);
            $staging=dirname($destination).'/.indy-install-'.bin2hex(random_bytes(12));
            if (!mkdir($staging,0700)) { throw new \DomainException('Privater Installationsordner nicht anlegbar.'); }
            $created=false; $controller=null;
            try {
                $state=$staging.'/state'; PrivateFiles::directory($state);
                foreach ($manifest->hashes as $relative=>$hash) {
                    if (!str_starts_with($relative,'state/')) { continue; }
                    $target=$staging.'/'.$relative; PrivateFiles::directory(dirname($target));
                    if (!copy($package.'/'.$relative,$target) || !hash_equals($hash,hash_file('sha256',$target))) { throw new \DomainException('Datenkopie unvollständig oder geändert.'); }
                    chmod($target,0600);
                }
                // Only the new private working copy is marked, while its controller lease is held.
                $controller=fopen($state.'/.indy-controller.lock','x+b');
                if ($controller===false || !flock($controller,LOCK_EX|LOCK_NB)) { throw new \DomainException('Neue Datenablage nicht exklusiv vorbereitbar.'); }
                chmod($state.'/.indy-controller.lock',0600);
                PrivateFiles::write($state.'/.indy-managed-state','{"format":"gameserver-indy-state","version":1,"access_layout":"shared-data"}');
                $access=AccessStore::configuration(json_decode(PrivateFiles::read($state.'/data/access-settings.json',16384),true,flags:JSON_THROW_ON_ERROR));
                PrivateFiles::write($state.'/runtime.local.json',PrivateFiles::json((object) ['php'=>$php,'frankenphp'=>$frankenphp,
                    'port'=>$manifest->source_port,'family_port'=>$access['port'],'threads'=>6]));
                $cache=$staging.'/install-cache';
                $prepare=new Process([$php,$release.'/symfony/bin/console','slice:init','--no-interaction'], $release.'/symfony',
                    ['APP_ENV'=>'prod','APP_DEBUG'=>'0','SLICE_STATE_DIR'=>$state,'SLICE_DRIVER'=>'managed','SLICE_ROLE'=>'owner',
                     'SLICE_PORT'=>(string)$manifest->source_port,'INDY_CACHE_DIR'=>$cache],timeout:120);
                $prepare->mustRun();
                // The installation subprocess does no observation, reconciliation, scheduling or sending.
                if (!is_file($state.'/slice.sqlite') || file_exists($state.'/runtime-lifecycle.json')) { throw new \DomainException('Tabellenvorbereitung nicht vollständig oder unerwartet aktiv.'); }
                $this->snapshots->verify($package); $this->snapshots->assertCurrent($manifest); $this->releases->verify($release,$commit);
                $this->remove($cache); // Never carry compiled absolute staging paths into the final state.
                PrivateFiles::write($staging.'/installation.json',PrivateFiles::json((object) ['format'=>'gameserver-indy-state-installation','version'=>1,
                    'commit'=>$commit,'release'=>$release,'snapshot_sha256'=>hash_file('sha256',$package.'/manifest.json'),'activated'=>false]));
                if (file_exists($destination) || is_link($destination) || !mkdir($destination,0700)) { throw new \DomainException('Installationsziel inzwischen belegt.'); } $created=true;
                // The lease file moves with its inode; no controller can adopt the new state halfway.
                if (!rename($state,$destination.'/state') || !rename($staging.'/installation.json',$destination.'/installation.json')) { throw new \DomainException('Vorbereitete Daten nicht veröffentlichbar.'); }
                return ['directory'=>$destination.'/state','commit'=>$commit,'prepared'=>true,'activated'=>false];
            } catch (\Throwable $error) {
                if ($created) { $this->remove($destination); } throw $error;
            } finally {
                if (is_resource($controller)) { flock($controller,LOCK_UN); fclose($controller); }
                $this->remove($staging);
            }
        });
    }
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name!=='.' && $name!=='..') { $this->remove($path.'/'.$name); } } rmdir($path);
        } elseif (file_exists($path) || is_link($path)) { unlink($path); }
    }
}
