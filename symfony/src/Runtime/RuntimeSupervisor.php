<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Access\AccessStore;
use App\Storage\PrivateFiles;
use Symfony\Component\Process\Process;

/** One lifetime owner of all application children; game processes survive manager shutdown. */
final class RuntimeSupervisor
{
    private bool $stopping = false;
    private array $recycles = [];
    public function __construct(private readonly SliceRuntime $runtime,private readonly RuntimeSettings $settings,
        private readonly RuntimeLifecycle $lifecycle,private readonly AccessStore $access,
        private readonly \App\Integration\Discord $discord,private readonly \App\WhatsApp\DeliveryStore $whatsapp,
        private readonly \App\Community\CommunityLedger $community,
        private readonly string $projectDirectory,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param:'kernel.environment')] private readonly string $environment) {}
    public function stop(): void { $this->stopping = true; }
    public function run(): void
    {
        if ($this->stopping) return;
        if (PHP_OS_FAMILY!=='Linux') { throw new \DomainException('Diesen Supervisor in WSL/Linux betreiben; native Windows-HTTP-Prüfung bleibt getrennt.'); }
        $this->runtime->assertInitialized(); $settings = $this->settings->read();
        if ($this->runtime->driver !== 'managed' || ($this->environment !== 'test' && !is_file($this->runtime->directory.'/.indy-managed-state'))) {
            throw new \DomainException('Produktive Datenübernahme zuerst ausdrücklich vorbereiten.');
        }
        if ($this->environment === 'test' && (!str_starts_with(basename(dirname($this->runtime->directory)),'indy-symfony-dashboard-')
            || $settings['port'] < 18800 || $settings['port'] > 18999 || $settings['family_port'] < 18800 || $settings['family_port'] > 18999)) { throw new \DomainException('Eigene isolierte Supervisor-Fixture erforderlich.'); }
        $lockPath = $this->runtime->directory.'/.indy-controller.lock';
        if (is_link($lockPath)) { throw new \DomainException('Controller-Sperre darf kein Link sein.'); }
        $lease = fopen($lockPath,'c+b'); chmod($lockPath,0600);
        if ($lease === false || !flock($lease,LOCK_EX|LOCK_NB)) { if (is_resource($lease)) fclose($lease); throw new \DomainException('Eine Verwaltung besitzt diese Datenablage bereits.'); }
        $children = []; $opened = false; $listeners = []; $guard = null;
        try {
            // Refuse existing listeners before changing lifecycle/claims; never adopt a foreign service.
            foreach ([$settings['port'],$settings['family_port']] as $port) {
                $listener = @stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$error);
                if ($listener === false) { throw new \DomainException('Ein Dashboardport ist bereits belegt. Bestehende Verwaltung zuerst geordnet beenden.'); }
                $listeners[] = $listener;
            }
            if ($this->stopping) return;
            $guard = new RuntimeGuard($this->runtime->directory,$listeners);
            $epoch = $this->lifecycle->open($guard->owner); $opened = true;
            $this->discord->reconcile(); $this->whatsapp->reconcile(); $this->community->reconcile();
            $folder = $this->runtime->directory.'/runtime'; PrivateFiles::directory($folder); PrivateFiles::directory($folder.'/logs');
            $public = str_replace('\\','/',$this->projectDirectory.'/public');
            if (strpbrk($public,"\"\r\n\0") !== false) { throw new \DomainException('Unzulässiger Releasepfad.'); }
            $sites = '';
            foreach ([$settings['port'],$settings['family_port']] as $port) {
                $sites .= 'http://:'.$port." {\n bind 127.0.0.1\n root * \"".$public."\"\n request_body {\n max_size 1048576\n }\n php_server {\n env INDY_LISTENER_PORT ".$port."\n }\n}\n";
            }
            PrivateFiles::write($folder.'/Caddyfile',"{\n admin off\n auto_https off\n persist_config off\n frankenphp {\n num_threads ".$settings['threads']."\n max_threads ".$settings['threads']."\n }\n}\n".$sites);
            $environment = ['APP_ENV'=>$this->environment,'SLICE_STATE_DIR'=>$this->runtime->directory,'SLICE_DRIVER'=>'managed','SLICE_ROLE'=>'owner','SLICE_PORT'=>(string)$settings['port'],
                'INDY_CACHE_DIR'=>$folder.'/cache-'.substr(hash('sha256',$this->projectDirectory),0,16),
                'XDG_CONFIG_HOME'=>$folder.'/caddy-config','XDG_DATA_HOME'=>$folder.'/caddy-data'];
            $console = [$settings['php'],$this->projectDirectory.'/bin/console'];
            foreach (['control-1'=>['messenger:consume','async'],'control-2'=>['messenger:consume','async'],'metadata'=>['messenger:consume','metadata'],
                'observer'=>['indy:observe'],'scheduler'=>['app:schedule']] as $name=>$arguments) {
                if (str_starts_with($name,'control') || $name === 'metadata') { array_push($arguments,'--sleep=0','--memory-limit=128M','--no-interaction'); }
                $children[$name] = new Process([...$console,...$arguments],$this->projectDirectory,$environment); $children[$name]->setTimeout(null);
            }
            foreach ($listeners as $listener) fclose($listener); $listeners = [];
            $children['http'] = new Process([$settings['frankenphp'],'run','--config',$folder.'/Caddyfile'],$this->projectDirectory,$environment); $children['http']->setTimeout(null);
            foreach ($children as $name=>$child) {
                if ($this->stopping) return;
                $file = $folder.'/logs/'.$name.'.log'; if (is_file($file) && filesize($file)>1048576) { rename($file,$file.'.1'); }
                $child->start($this->log($file));
            }
            $this->record($children,$folder,$epoch);
            $deadline = microtime(true)+30; $ready = false;
            while (!$this->stopping && microtime(true)<$deadline) {
                $guard->assertAlive();
                $this->healthy($children,$folder,$epoch);
                $context = stream_context_create(['http'=>['timeout'=>.5,'ignore_errors'=>true]]);
                $bytes = @file_get_contents('http://127.0.0.1:'.$settings['port'].'/api/session',false,$context);
                $session = is_string($bytes) ? json_decode($bytes,true) : null;
                if (($session['driver'] ?? null)==='managed' && ($session['api_version'] ?? null)===2) { $ready=true; break; }
                usleep(100000);
            }
            if (!$ready && !$this->stopping) { throw new \DomainException('Dashboard wurde nicht bereit. Private Laufzeitprotokolle prüfen.'); }
            if ($ready) {
                $access = $this->access->settings();
                PrivateFiles::write($this->runtime->directory.'/listener-runtime.json',PrivateFiles::json((object) ['active'=>$access['enabled'] && $access['port']===$settings['family_port'], 'hostname'=>$access['hostname'], 'port'=>$settings['family_port'],'epoch'=>$epoch]));
                while (!$this->stopping) { $guard->assertAlive(); $this->healthy($children,$folder,$epoch); usleep(100000); }
            }
        } finally {
            try { if ($opened) { $this->lifecycle->close(); } }
            finally {
                try {
                    foreach ($listeners as $listener) { fclose($listener); }
                    $deadline=microtime(true)+15;
                    foreach (array_reverse($children) as $child) {
                        try { if ($child->isStarted() && $child->isRunning() && defined('SIGTERM')) { $child->signal(SIGTERM); } } catch (\Throwable) {}
                    }
                    foreach (array_reverse($children) as $child) {
                        // Only owned web/worker/observer processes; never the dedicated server EXE.
                        try { if ($child->isStarted() && $child->isRunning()) { $child->stop(max(0,$deadline-microtime(true))); } } catch (\Throwable) {}
                    }
                } finally { try { $guard?->finish(); } finally { flock($lease,LOCK_UN); fclose($lease); } }
            }
        }
    }
    private function log(string $file): \Closure
    {
        return static function ($type,$chunk) use ($file): void {
            // No HTTP request tracing; both framework diagnostics and capture buffers stay bounded.
            clearstatcache(true,$file);
            if (!is_file($file) || filesize($file)<2097152) { file_put_contents($file,$chunk,FILE_APPEND|LOCK_EX); chmod($file,0600); }
        };
    }
    private function record(array $children,string $folder,string $epoch): void
    {
        PrivateFiles::write($folder.'/children.json',PrivateFiles::json((object) ['epoch'=>$epoch,'supervisor'=>getmypid(),
            'children'=>(object) array_map(fn(Process $child)=>$child->getPid(),$children)]));
    }
    private function healthy(array &$children,string $folder,string $epoch): void
    {
        foreach ($children as $name=>$child) {
            if ($child->isStarted() && !$child->isRunning()) {
                $now=microtime(true); $this->recycles[$name]=array_filter($this->recycles[$name] ?? [],fn($time)=>$now-$time<60);
                // Messenger's clean memory-limit exit happens after acknowledgement; claims are not replayed.
                if (!$this->stopping && in_array($name,['control-1','control-2','metadata'],true) && $child->getExitCode()===0
                    && count($this->recycles[$name])<3 && $this->lifecycle->epoch()===$epoch) {
                    $this->recycles[$name][]=$now; $children[$name]=$child->restart($this->log($folder.'/logs/'.$name.'.log'));
                    $this->record($children,$folder,$epoch); continue;
                }
                throw new \DomainException('Verwaltungsprozess '.$name.' wurde beendet. Private Laufzeitprotokolle prüfen.');
            }
            if ($child->isStarted()) { $child->clearOutput(); $child->clearErrorOutput(); }
        }
    }
}
