<?php
declare(strict_types=1);
namespace App\Instance;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class InstanceConfiguration
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly AccessPolicy $policy, private readonly InstanceMutation $mutation,
        private readonly NativePaths $paths, private readonly WorldBinding $worlds, private readonly SliceRuntime $runtime,
        private readonly string $projectDirectory) {}
    public function view(Principal $actor, string $id): array
    {
        $this->policy->require($actor, 'config.read', $id); $profile = $this->registry->get($id);
        $path = $this->registry->configPath($profile); $adapter = $this->games->adapter($profile->game);
        if (!is_file($path)) { return ['server_id' => $id, 'config' => null, 'revision' => null]; }
        $bytes = PrivateFiles::read($path);
        return ['server_id' => $id, 'config' => $adapter->publicConfiguration($adapter->decode($bytes)), 'revision' => hash('sha256', $bytes)];
    }
    public function save(Principal $actor, string $id, \stdClass $configuration, string $revision): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $revision)) { throw new \InvalidArgumentException('Gültiger Dateistand erforderlich.'); }
        return $this->change($actor, $id, 'config.write', function (\stdClass $profile, array $status) use ($configuration, $revision): array {
            $path = $this->registry->configPath($profile); $before = PrivateFiles::read($path);
            if (!hash_equals(hash('sha256', $before), $revision)) { throw new \DomainException('Die Konfiguration wurde inzwischen geändert. Gespeicherten Stand neu laden; der Entwurf wurde nicht geschrieben.'); }
            $adapter = $this->games->adapter($profile->game); $previous = $adapter->decode($before);
            $configuration = $adapter->merge($configuration, $previous); $adapter->validate($configuration);
            $adapter->validateEdit($configuration, $previous, $profile);
            $this->exclusive($profile, $configuration, $status);
            $bytes = $adapter->encode($configuration, $before);
            $folder = $this->registry->dataPath($profile->id).'/backups'; PrivateFiles::directory($folder);
            $name = gmdate('Ymd-His-').bin2hex(random_bytes(6)).'.'.pathinfo($path, PATHINFO_EXTENSION);
            PrivateFiles::write($folder.'/'.$name, $before);
            // A game/editor outside the manager can still modify the file after the initial read.
            if (!hash_equals($revision, hash('sha256', PrivateFiles::read($path)))) { throw new \DomainException('Die Datei wurde während der Vorbereitung geändert. Erneut laden.'); }
            PrivateFiles::write($path, $bytes);
            return ['server_id' => $profile->id, 'revision' => hash('sha256', $bytes), 'backup' => $name,
                'message' => 'Einstellungen gespeichert; vorherige Datei unverändert gesichert.'];
        });
    }
    public function setup(Principal $actor, string $id): array
    {
        return $this->change($actor, $id, 'server.setup', function (\stdClass $profile, array $status): array {
            $schema = $this->games->schema($profile->game); $adapter = $this->games->adapter($profile->game);
            if (!is_file(rtrim($this->paths->local($profile->server_directory), '/\\').'/'.$schema->executable)) { throw new \DomainException('Serverprogramm nicht gefunden.'); }
            $path = $this->registry->configPath($profile); $created = !file_exists($path);
            $configuration = $created ? $adapter->initial() : $adapter->decode(PrivateFiles::read($path)); $adapter->validate($configuration);
            if ($created) { $this->exclusive($profile, $configuration, $status); PrivateFiles::write($path, $adapter->encode($configuration, '')); }
            return ['server_id' => $profile->id, 'created' => $created, 'message' => 'Server vorbereitet. Zugangsdaten stehen in den Einstellungen.'];
        });
    }
    private function change(Principal $actor, string $id, string $permission, callable $callback): array
    {
        $this->policy->require($actor, $permission, $id); $this->registry->get($id);
        // Cross-profile world changes are short file transactions, never long game operations.
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('configuration-bindings');
        if (!$lock->acquire()) { throw new \DomainException('Eine Konfigurationsänderung wird bereits vorbereitet.'); }
        try { return $this->mutation->stopped($actor, $id, $permission, fn ($status) => $callback($this->registry->get($id), $status)); }
        finally { $lock->release(); }
    }
    private function exclusive(\stdClass $profile, \stdClass $configuration, array $status): void
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
