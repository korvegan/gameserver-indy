<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Storage\PrivateFiles;
use Psr\Log\LoggerInterface;

/** Only trusted registered profiles and game schemas can select a native executable. */
final class WindowsProcessGateway implements ProcessGateway
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games, private readonly NativePowerShell $powershell,
        private readonly string $projectDirectory, private readonly LoggerInterface $logger,
        private readonly RuntimeLifecycle $lifecycle) {}
    public function call(string $serverId, string $action): array
    {
        $this->runtime->assertInitialized();
        if ($this->runtime->driver !== 'managed' || !in_array($action, ['Status', 'Start', 'Stop'], true)) {
            throw new \DomainException('Der native Profiladapter ist in diesem Laufzeitmodus nicht aktiviert.');
        }
        $profile = $this->registry->get($serverId); $schema = $this->games->schema($profile->game);
        if ($action !== 'Status' && ($schema->capabilities->process_control->state ?? 'unknown') !== 'supported') {
            throw new \DomainException('Für dieses Spiel ist kein geprüfter Prozessadapter freigegeben.');
        }
        $folder = $this->runtime->directory.'/native/requests'; PrivateFiles::directory($folder);
        $nonce = bin2hex(random_bytes(16)); $request = $folder.'/request-'.$nonce.'.json'; $reply = $folder.'/reply-'.$nonce.'.json';
        $arguments = (object) ['Action' => $action, 'ServerDirectory' => $this->powershell->windowsPath($profile->server_directory),
            'ExecutableName' => $schema->executable, 'SteamAppId' => $schema->launch_steam_app_id ?? 0,
            'ConfigArgument' => $schema->config_argument ?? '', 'ConfigPath' => $this->powershell->windowsPath($this->registry->configPath($profile)),
            'DiagnosticDirectory' => $this->powershell->windowsPath($this->registry->diagnosticPath($profile)),
            'MetricsCachePath'=>$this->powershell->windowsPath($this->runtime->directory.'/observations/host-metrics.json')];
        if (($profile->launcher_script ?? '') !== '') { $arguments->LauncherScript = $this->powershell->windowsPath($profile->launcher_script); }
        if ($action !== 'Status') {
            $arguments->RuntimeEpoch = $this->lifecycle->epoch();
            $arguments->RuntimeFencePath = $this->powershell->windowsPath($this->runtime->directory.'/runtime-lifecycle.json');
        }
        try {
            PrivateFiles::write($request, PrivateFiles::json($arguments)); PrivateFiles::write($reply, '{}');
            $process = $this->powershell->run($this->projectDirectory.'/tools/invoke-managed-server.ps1',
                ['-RequestPath', $this->powershell->windowsPath($request)], $action === 'Status' ? 12 : 70);
            $response = PrivateFiles::object(PrivateFiles::read($reply, 32768));
            if (!$process->isSuccessful() || ($response->ok ?? null) !== true || !($response->result ?? null) instanceof \stdClass) {
                throw new \RuntimeException('Ungültige native Antwort.');
            }
            return $this->normalize($response->result);
        } catch (\Throwable $error) {
            $this->logger->error('Native profile call failed ({class})', ['class' => $error::class]);
            throw new OutcomeUnknown('Windows-Prozessaufruf fehlgeschlagen oder Ausgang unklar. Profilprotokoll prüfen; kein automatischer Wiederholungsversuch.', previous: $error);
        } finally {
            foreach ([$request, $reply] as $file) { if (file_exists($file)) { unlink($file); } }
        }
    }
    private function normalize(\stdClass $response): array
    {
        if (!is_array($response->processes ?? null) || !is_bool($response->launcher_running ?? null)
            || !is_string($response->user_profile ?? null) || $response->user_profile === '') {
            throw new \DomainException('Unvollständiger nativer Prozesszustand.');
        }
        $processes = [];
        foreach ($response->processes as $process) {
            if (!$process instanceof \stdClass || !is_int($process->pid ?? null) || $process->pid < 1
                || !is_string($process->started ?? null) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?(?:Z|\+00:00)$/D', $process->started)) {
                throw new \DomainException('Ungültige native Prozessidentität.');
            }
            $entry = ['pid' => $process->pid, 'started' => $process->started];
            foreach (['cpu_seconds','memory_bytes','io_bytes'] as $metric) {
                $value = $process->{$metric} ?? null;
                $entry[$metric] = (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>=0 ? $value : null;
            }
            $processes[] = $entry;
        }
        $host = [];
        foreach (['logical_processors','cpu_percent','memory_used_bytes','memory_total_bytes','observed_at'] as $metric) {
            $value = $response->host_metrics->{$metric} ?? null;
            $host[$metric] = (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>=0 ? $value : null;
        }
        return ['known' => true, 'running' => (bool) $processes, 'ready' => null,
            'starting' => $response->launcher_running && !$processes, 'launcher_running' => $response->launcher_running,
            'pid' => count($processes) === 1 ? $processes[0]['pid'] : null, 'processes' => $processes, 'user_profile' => $response->user_profile, 'host_metrics'=>$host];
    }
}
