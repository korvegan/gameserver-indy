<?php
declare(strict_types=1);
namespace App\Integration;
use App\Runtime\NativePowerShell;
final class WindowsNetwork implements HostNetworkProvider
{
    public function __construct(private readonly NativePowerShell $native, private readonly string $projectDirectory) {}
    public function collect(): array
    {
        $process = $this->native->run(dirname($this->projectDirectory).'/scripts/windows-network.ps1', [], 25);
        if (!$process->isSuccessful() || strlen($process->getOutput()) > 65536) { throw new \DomainException('Windows-Netzwerkdaten nicht lesbar.'); }
        $raw = json_decode(ltrim($process->getOutput(), "\xef\xbb\xbf"), true, flags: JSON_THROW_ON_ERROR);
        return is_array($raw) ? $raw : throw new \DomainException('Windows-Netzwerkdaten nicht lesbar.');
    }
}
