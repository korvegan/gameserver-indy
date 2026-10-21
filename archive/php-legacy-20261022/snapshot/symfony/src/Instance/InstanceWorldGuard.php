<?php
declare(strict_types=1);
namespace App\Instance;

use App\Game\GameCatalog;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

final class InstanceWorldGuard
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly WorldBinding $worlds, private readonly NativePaths $paths,
        private readonly SliceRuntime $runtime, private readonly string $projectDirectory) {}
    public function pending(string $id): void
    {
        $path = $this->runtime->directory.'/data/world-backups/'.$id.'/restore-pending.json';
        if (file_exists($path) || is_link($path)) { throw new \DomainException('Eine Weltwiederherstellung benötigt zuerst die ausdrückliche Rückkehr zum gesicherten Stand.'); }
    }
    public function exclusive(\stdClass $profile, \stdClass $configuration, array $status): void
    {
        $own = $this->worlds->resolve($profile, $configuration, $status['user_profile'] ?? null);
        if ($own['world'] !== null) {
            foreach ([$this->runtime->directory, dirname($this->projectDirectory)] as $protected) {
                if ($this->paths->contains($own['world'], $protected)) { throw new \DomainException('Weltordner und Verwaltungsdaten überschneiden sich.'); }
            }
            $storage = $this->runtime->directory.'/data/world-backups';
            if ($this->paths->contains($own['world'], $storage) || $this->paths->contains($storage, $own['world'])) { throw new \DomainException('Weltordner und Sicherungsablage überschneiden sich.'); }
        }
        foreach ($this->registry->settings()->servers as $other) {
            if ($other->id === $profile->id) { continue; }
            $path = $this->registry->configPath($other);
            $otherConfig = is_file($path) ? $this->games->adapter($other->game)->decode(PrivateFiles::read($path)) : null;
            $binding = $otherConfig === null ? ['world' => null, 'files' => [$path]] : $this->worlds->resolve($other, $otherConfig, $status['user_profile'] ?? null);
            if ($this->worlds->overlaps($own, $binding)) { throw new \DomainException('Mehrere Profile verwenden dieselben Welt- oder Konfigurationsdateien. Getrennte Speicherorte erforderlich.'); }
        }
    }
}
