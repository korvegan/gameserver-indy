<?php
declare(strict_types=1);

namespace App\Runtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/** Fixed disposable executable and profile paths; never accepts a browser path. */
final class WindowsStandinControl implements ServerControl
{
    public const EXECUTABLE = 'indy-symfony-standin.exe';

    public function __construct(private readonly SliceRuntime $runtime, private readonly string $projectDirectory,
        private readonly LoggerInterface $logger) {}

    public function prepare(): void
    {
        $this->runtime->assertInitialized();
        $process = new Process([$this->powershell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $this->windowsPath($this->projectDirectory.'/tools/prepare-standin.ps1'),
            '-OutputDirectory', $this->windowsPath($this->runtime->directory.'/native')]);
        $process->setTimeout(40)->mustRun();
    }

    public function status(string $serverId): array
    {
        $response = $this->call($serverId, 'Status');
        $pid = isset($response['processes'][0]['pid']) ? (int) $response['processes'][0]['pid'] : null;
        $marker = $this->runtime->directory.'/native/'.$serverId.'/ready.pid';
        return ['running' => $pid !== null, 'ready' => $pid !== null && is_file($marker)
            && trim(file_get_contents($marker)) === (string) $pid, 'pid' => $pid];
    }

    public function execute(string $serverId, string $operation): void
    {
        if (!in_array($operation, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unbekannter Befehl.');
        }
        if ($operation !== 'start') {
            $this->call($serverId, 'Stop');
        }
        if ($operation !== 'stop') {
            $this->call($serverId, 'Start');
        }
    }

    private function call(string $serverId, string $action): array
    {
        $this->runtime->assertInitialized();
        $this->runtime->server($serverId);
        $directory = $this->runtime->directory.'/native/'.$serverId;
        $executable = $directory.'/'.self::EXECUTABLE;
        $checksum = $directory.'/fixture.sha256';
        if (is_link($directory) || !is_file($checksum) || !is_file($executable)
            || trim(file_get_contents($checksum)) !== hash_file('sha256', $executable)) {
            throw new \RuntimeException('Windows-Testprogramm fehlt oder wurde verändert.');
        }
        $nonce = bin2hex(random_bytes(16));
        $reply = $directory.'/reply-'.$nonce.'.json';
        $process = new Process([$this->powershell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $this->windowsPath($this->projectDirectory.'/tools/invoke-standin.ps1'),
            '-Action', $action, '-StateDirectory', $this->windowsPath($this->runtime->directory),
            '-ServerId', $serverId, '-ReplyNonce', $nonce]);
        try {
            $process->setTimeout(65)->mustRun();
            if (!is_file($reply) || !chmod($reply, 0600)) { throw new \RuntimeException('Testantwort fehlt oder ist nicht schützbar.'); }
            return json_decode(file_get_contents($reply), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            $this->logger->error('Isolierter Windows-Testaufruf {action} fehlgeschlagen: {error}',
                ['action' => $action, 'error' => $error->getMessage()]);
            // Do not expose script arguments, paths or native diagnostics to the browser.
            throw new OutcomeUnknown('Windows-Aufruf fehlgeschlagen oder Ausgang unklar. Testprozess prüfen; kein automatischer Wiederholungsversuch.', previous: $error);
        } finally {
            if (is_file($reply)) { unlink($reply); }
        }
    }

    private function powershell(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
    }

    private function windowsPath(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $path;
        }
        $process = new Process(['wslpath', '-w', $path]);
        $process->setTimeout(10)->mustRun();
        return trim($process->getOutput());
    }
}
