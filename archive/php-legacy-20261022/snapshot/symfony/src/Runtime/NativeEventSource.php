<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Log\GameLogSource;
use App\Storage\PrivateFiles;

/** Only observes registered processes/files. It never receives a game-control verb. */
final class NativeEventSource
{
    private array $children = [];
    private ?string $configuration = null;
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games, private readonly GameLogSource $logs, private readonly NativePowerShell $powershell,
        private readonly string $projectDirectory) {}
    public function start(): void
    {
        $this->close(); $profiles = []; $linux = [];
        $nativePath = function (?string $path, string $token) use (&$linux): ?string {
            if ($path === null) { return null; }
            if (PHP_OS_FAMILY === 'Linux' && str_starts_with($path, '/') && !preg_match('~^/mnt/[a-z]/~i', $path)) {
                array_push($linux, $token, $path); return null;
            }
            return $this->powershell->windowsPath($path);
        };
        foreach ($this->registry->settings()->servers as $profile) {
            try { $log = $this->logs->path($profile->id); } catch (\Throwable) { $log = null; }
            $profiles[] = (object)['id'=>$profile->id, 'executable'=>$this->games->schema($profile->game)->executable,
                'log'=>$nativePath($log, 'log:'.$profile->id),
                'launcher'=>$nativePath($this->registry->diagnosticPath($profile).'/launcher.log', 'log:'.$profile->id),
                'config'=>$nativePath($this->registry->configPath($profile), 'config:'.$profile->id)];
        }
        $folder = $this->runtime->directory.'/runtime'; PrivateFiles::directory($folder);
        $this->configuration = $folder.'/event-source-'.getmypid().'.json';
        PrivateFiles::write($this->configuration, PrivateFiles::json((object)['profiles'=>$profiles]));
        $executable = PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
        $this->launch([$executable, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
            $this->powershell->windowsPath($this->projectDirectory.'/tools/watch-game-events.ps1'),
            '-ConfigurationPath', $this->powershell->windowsPath($this->configuration)]);
        if ($linux) { $this->launch([$this->linuxBridge($folder), ...$linux]); }
    }
    private function linuxBridge(string $folder): string
    {
        $source = $this->projectDirectory.'/tools/watch-files.c'; $path = $folder.'/watch-files-'.substr(hash_file('sha256', $source), 0, 16);
        if (!is_file($path)) {
            $temporary = tempnam($folder, '.watch-');
            try {
                (new \Symfony\Component\Process\Process(['cc','-O2','-Wall','-Wextra','-Werror',$source,'-o',$temporary]))->setTimeout(15)->mustRun();
                chmod($temporary, 0700); rename($temporary, $path);
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        }
        return $path;
    }
    private function launch(array $command): void
    {
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Native Ereignisquelle nicht startbar.'); }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $this->children[(int)$pipes[1]] = ['process'=>$process, 'pipes'=>$pipes, 'buffer'=>''];
    }
    public function streams(): array { return array_map(fn ($child) => $child['pipes'][1], $this->children); }
    public function read(mixed $stream): array
    {
        $child = &$this->children[(int)$stream]; $pipes = $child['pipes'];
        $child['buffer'] .= stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // Never forward native diagnostics or paths to the public stream.
        if (strlen($child['buffer'])>1048576) { $child['buffer'] = ''; return [['kind'=>'rescan','source'=>(int)$stream]]; }
        $events = [];
        while (($end = strpos($child['buffer'], "\n")) !== false) {
            $line = substr($child['buffer'],0,$end); $child['buffer'] = substr($child['buffer'],$end+1);
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['kind'])) { $events[] = [...$event, 'source'=>(int)$stream]; }
        }
        if (feof($pipes[1])) { throw new \RuntimeException('Native Ereignisquelle beendet.'); }
        return $events;
    }
    public function close(): void
    {
        foreach ($this->children as $child) { if (is_resource($child['pipes'][0])) { fclose($child['pipes'][0]); } }
        // Input EOF ends only this owned native observer, never a game process.
        foreach ($this->children as $child) {
            $process = $child['process']; $pipes = $child['pipes'];
            $deadline = microtime(true)+2;
            do { $status = proc_get_status($process); if (!$status['running']) { break; } usleep(20000); } while (microtime(true)<$deadline);
            if ($status['running']) { proc_terminate($process); }
            foreach ([1,2] as $index) { if (is_resource($pipes[$index])) { fclose($pipes[$index]); } }
            proc_close($process);
        }
        $this->children = [];
        if ($this->configuration !== null && is_file($this->configuration)) { unlink($this->configuration); }
    }
}
