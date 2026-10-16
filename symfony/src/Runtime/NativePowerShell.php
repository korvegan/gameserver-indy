<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Instance\NativePaths;
use Symfony\Component\Process\Process;

final class NativePowerShell
{
    public function __construct(private readonly NativePaths $paths) {}
    public function windowsPath(string $path): string
    {
        $this->paths->absolute($path);
        if (PHP_OS_FAMILY === 'Windows' || str_starts_with($path, '\\\\') || preg_match('~^[a-z]:[/\\\\]~i', $path)) {
            return $path;
        }
        $conversion = new Process(['wslpath', '-w', $path]);
        $conversion->setTimeout(10)->mustRun();
        $converted = trim($conversion->getOutput()); $this->paths->absolute($converted);
        return $converted;
    }
    public function run(string $script, array $arguments, float $timeout = 70): Process
    {
        $executable = PHP_OS_FAMILY === 'Windows' ? 'powershell.exe'
            : '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
        $process = new Process([$executable, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $this->windowsPath($script), ...$arguments]);
        $process->setTimeout($timeout)->run();
        return $process;
    }
}
