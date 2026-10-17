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
    private mixed $process = null;
    private array $pipes = [];
    private ?string $configuration = null;
    private string $buffer = '';
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games, private readonly GameLogSource $logs, private readonly NativePowerShell $powershell,
        private readonly string $projectDirectory) {}
    public function start(): void
    {
        $this->close(); $profiles = [];
        foreach ($this->registry->settings()->servers as $profile) {
            try { $log = $this->logs->path($profile->id); } catch (\Throwable) { $log = null; }
            $profiles[] = (object)['id'=>$profile->id, 'executable'=>$this->games->schema($profile->game)->executable,
                'log'=>$log ? $this->powershell->windowsPath($log) : null,
                'config'=>$this->powershell->windowsPath($this->registry->configPath($profile))];
        }
        $folder = $this->runtime->directory.'/runtime'; PrivateFiles::directory($folder);
        $this->configuration = $folder.'/event-source-'.getmypid().'.json';
        PrivateFiles::write($this->configuration, PrivateFiles::json((object)['profiles'=>$profiles]));
        $executable = PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
        $this->process = proc_open([$executable, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
            $this->powershell->windowsPath($this->projectDirectory.'/tools/watch-game-events.ps1'),
            '-ConfigurationPath', $this->powershell->windowsPath($this->configuration)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $this->pipes);
        if (!is_resource($this->process)) { throw new \RuntimeException('Native Ereignisquelle nicht startbar.'); }
        stream_set_blocking($this->pipes[1], false); stream_set_blocking($this->pipes[2], false); $this->buffer = '';
    }
    public function stream(): mixed { return $this->pipes[1] ?? null; }
    public function read(): array
    {
        $this->buffer .= stream_get_contents($this->pipes[1]);
        stream_get_contents($this->pipes[2]); // Never forward native diagnostics or paths to the public stream.
        if (strlen($this->buffer)>1048576) { $this->buffer = ''; return [['kind'=>'rescan']]; }
        $events = [];
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer,0,$end); $this->buffer = substr($this->buffer,$end+1);
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['kind'])) { $events[] = $event; }
        }
        if (feof($this->pipes[1])) { throw new \RuntimeException('Native Ereignisquelle beendet.'); }
        return $events;
    }
    public function close(): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) { fclose($this->pipes[0]); }
        // Input EOF ends only this owned native observer, never a game process.
        if (is_resource($this->process)) {
            $deadline = microtime(true)+2;
            do { $status = proc_get_status($this->process); if (!$status['running']) { break; } usleep(20000); } while (microtime(true)<$deadline);
            if ($status['running']) { proc_terminate($this->process); }
            foreach ([1,2] as $index) { if (isset($this->pipes[$index]) && is_resource($this->pipes[$index])) { fclose($this->pipes[$index]); } }
            proc_close($this->process);
        }
        $this->process = null; $this->pipes = [];
        if ($this->configuration !== null && is_file($this->configuration)) { unlink($this->configuration); }
    }
}
