<?php
declare(strict_types=1);
namespace App\Backup;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\InstanceWorldGuard;
use App\Instance\NativePaths;
use App\Instance\WorldBinding;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

final class BackupPlan
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly WorldBinding $worlds, private readonly InstanceWorldGuard $worldGuard, private readonly BackupFiles $files,
        private readonly NativePaths $paths, private readonly SliceRuntime $runtime) {}
    public function resolve(string $id, array $status, ?\stdClass $configuration = null): array
    {
        $profile = $this->registry->get($id); $configPath = $this->registry->configPath($profile); $storedConfig = $this->files->bindingPath($configPath);
        if ($this->paths->contains($profile->server_directory, $this->runtime->directory.'/data/world-backups/'.$id)) {
            throw new \DomainException('Weltsicherungen müssen außerhalb der Spielinstallation liegen.');
        }
        $this->files->native([$configPath]); $configPath = $this->files->plain($configPath);
        $adapter = $this->games->adapter($profile->game);
        $configuration ??= $adapter->decode(PrivateFiles::read($configPath, BackupFiles::CONFIG_LIMIT)); $adapter->validate($configuration);
        $source = $this->worlds->resolve($profile, $configuration, $status['user_profile'] ?? null);
        if ($source['world'] === null) { throw new \DomainException('Eine eindeutige feste Welt-ID ist für die Sicherung erforderlich.'); }
        $this->files->native([$source['world'], ...$source['files']]);
        $world = $this->files->plain($source['world']); $files = ['config/server' => $configPath];
        if (isset($source['files'][1])) { $files['config/preset'] = $this->files->plain($source['files'][1]); }
        $this->worldGuard->exclusive($profile, $configuration, $status);
        $storedFiles = ['config/server' => $storedConfig];
        if (isset($source['files'][1])) { $storedFiles['config/preset'] = $this->files->bindingPath($source['files'][1]); }
        return ['world' => $world, 'files' => $files, 'binding' => (object) ['server_id' => $id, 'game' => $profile->game,
            'save_id' => $source['save_id'], 'world' => $this->files->bindingPath($source['world']), 'files' => (object) $storedFiles]];
    }
    public function token(array $plan): string
    {
        $hashes = [];
        foreach ($plan['files'] as $key => $path) { $hashes[$key] = $this->files->digest($path); }
        return hash('sha256', json_encode([$plan['binding'], $hashes], JSON_THROW_ON_ERROR));
    }
    public function sameBinding(mixed $one, mixed $two): bool
    {
        if (!$one instanceof \stdClass || !$two instanceof \stdClass || !($one->files ?? null) instanceof \stdClass || !($two->files ?? null) instanceof \stdClass) { return false; }
        foreach (['server_id', 'game', 'save_id'] as $key) { if (!is_string($one->{$key} ?? null) || $one->{$key} !== ($two->{$key} ?? null)) { return false; } }
        $first = get_object_vars($one->files); $second = get_object_vars($two->files); ksort($first); ksort($second);
        if (array_keys($first) !== array_keys($second)) { return false; }
        try {
            foreach (['world' => $one->world ?? null, ...$first] as $key => $path) {
                $other = $key === 'world' ? ($two->world ?? null) : $second[$key];
                if (!is_string($path) || !is_string($other) || $this->paths->identity($path) !== $this->paths->identity($other)) { return false; }
            }
        } catch (\Throwable) { return false; }
        return true;
    }
    public function decode(string $id, string $bytes): \stdClass
    {
        return $this->games->adapter($this->registry->get($id)->game)->decode($bytes);
    }
}
