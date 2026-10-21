<?php
declare(strict_types=1);

namespace App\Runtime;

final class SelectedServerControl implements ServerControl
{
    private ServerControl $control;

    public function __construct(SliceRuntime $runtime, WindowsStandinControl $windows, ManagedServerControl $managed)
    {
        $this->control = match ($runtime->driver) { 'windows' => $windows, 'managed' => $managed, default => new SimulatedServerControl($runtime) };
    }

    public function status(string $serverId): array { return $this->control->status($serverId); }
    public function execute(string $serverId, string $operation): void { $this->control->execute($serverId, $operation); }
}
