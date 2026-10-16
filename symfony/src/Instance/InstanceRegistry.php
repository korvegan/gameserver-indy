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

/** Reload inside each metadata transaction; never replace newer settings from a cached copy. */
final class InstanceRegistry
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly GameCatalog $games,
        private readonly NativePaths $paths, private readonly AccessPolicy $policy) {}
    public function settings(): \stdClass
    {
        $this->runtime->assertInitialized(); $path = $this->runtime->directory.'/manager.local.json';
        if (!is_file($path)) { return (object) ['schema_version' => 2, 'servers' => []]; }
        return $this->validate(PrivateFiles::object(PrivateFiles::read($path)));
    }
    public function validate(\stdClass $settings): \stdClass
    {
        $settings = clone $settings;
        if (property_exists($settings, 'schema_version') && !in_array($settings->schema_version, [1, 2], true)) { throw new \DomainException('Unbekannte Profilversion.'); }
        if (!property_exists($settings, 'servers')) {
            $settings->servers = [(object) ['id' => 'enshrouded', 'game' => 'enshrouded', 'label' => 'Enshrouded',
                'server_directory' => property_exists($settings, 'server_directory') ? $settings->server_directory : $this->games->schema('enshrouded')->default_directory]];
        }
        if (!is_array($settings->servers) || !$settings->servers) { throw new \InvalidArgumentException('Mindestens ein Serverprofil ist erforderlich.'); }
        $ids = []; $directories = [];
        foreach ($settings->servers as $profile) {
            if (!$profile instanceof \stdClass) { throw new \InvalidArgumentException('Serverprofile müssen Objekte sein.'); }
            $this->validateProfile($profile); $identity = $this->paths->identity($profile->server_directory);
            if (in_array($profile->id, $ids, true) || in_array($identity, $directories, true)) { throw new \InvalidArgumentException('Profile benötigen eindeutige IDs und getrennte Installationsordner.'); }
            $ids[] = $profile->id; $directories[] = $identity;
        }
        if (property_exists($settings, 'catalog_games') && !is_array($settings->catalog_games)) { throw new \InvalidArgumentException('catalog_games muss eine Liste sein.'); }
        $this->games->entries($settings->catalog_games ?? []);
        return $settings;
    }
    public function validateProfile(\stdClass $profile): void
    {
        if (!GameCatalog::identifier($profile->id ?? null, 48) || in_array($profile->id, array_keys(SliceRuntime::SERVERS), true)) { throw new \InvalidArgumentException('Profil-ID: 1–48 kleine Buchstaben, Zahlen, Unterstriche oder Bindestriche; Test-IDs sind reserviert.'); }
        if (!is_string($profile->game ?? null)) { throw new \InvalidArgumentException('Ein Spiel ist erforderlich.'); }
        $schema = $this->games->schema($profile->game);
        if (!is_string($profile->label ?? null) || mb_strlen(trim($profile->label)) < 1 || mb_strlen(trim($profile->label)) > 80) { throw new \InvalidArgumentException('Profilname: 1–80 Zeichen.'); }
        $this->paths->absolute($profile->server_directory ?? null);
        foreach (['config_path', 'launcher_script', 'log_directory', 'steam_manifest'] as $key) {
            if (property_exists($profile, $key) && $profile->{$key} !== '') { $this->paths->absolute($profile->{$key}); }
        }
        if (property_exists($profile, 'steam_branch') && (!is_string($profile->steam_branch) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $profile->steam_branch))) { throw new \InvalidArgumentException('Ungültiger Steam-Branch.'); }
        if (($profile->launcher_script ?? '') !== '' && (!(bool) ($schema->external_launcher ?? false) || !str_ends_with(strtolower($profile->launcher_script), '.ps1'))) { throw new \InvalidArgumentException('Für dieses Spiel ist kein solcher PowerShell-Starter eingerichtet.'); }
        if (property_exists($profile, 'connection')) {
            if (!$profile->connection instanceof \stdClass || array_diff(array_keys(get_object_vars($profile->connection)), ['public_host'])) { throw new \InvalidArgumentException('Ungültige Beitrittseinstellungen.'); }
            $host = self::publicHost(property_exists($profile->connection, 'public_host') ? $profile->connection->public_host : '');
            if ($host !== '' && ($schema->connection->mode ?? null) !== 'ip_query') { throw new \InvalidArgumentException('Kein direkter IP-/Domain-Beitritt für dieses Spiel eingerichtet.'); }
        }
    }
    public static function publicHost(mixed $value): string
    {
        if (!is_string($value)) { throw new \InvalidArgumentException('Eine öffentliche Domain oder IPv4 ohne Protokoll und Port ist erforderlich.'); }
        $host = strtolower(trim($value)); if ($host === '') { return ''; }
        if (str_ends_with($host, '.')) { $host = substr($host, 0, -1); }
        if (strlen($host) > 253 || !str_contains($host, '.') || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host)
            || (preg_match('/^[0-9.]+$/D', $host) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false)) { throw new \InvalidArgumentException('Ungültige öffentliche Domain oder IPv4.'); }
        return $host;
    }
    public function get(string $id): \stdClass
    {
        foreach ($this->settings()->servers as $profile) { if ($profile->id === $id) { return $profile; } }
        throw new \InvalidArgumentException('Dieses Serverprofil existiert nicht.');
    }
    public function labels(): array
    {
        $labels = SliceRuntime::SERVERS;
        foreach ($this->settings()->servers as $profile) { $labels[$profile->id] = $profile->label; } return $labels;
    }
    public function configPath(\stdClass $profile): string
    {
        return $this->paths->local(($profile->config_path ?? '') !== '' ? $profile->config_path
            : rtrim($profile->server_directory, '/\\').'/'.$this->games->schema($profile->game)->config_filename);
    }
    public function dataPath(string $id): string
    {
        $this->get($id);
        return $this->runtime->directory.'/data'.($id === 'enshrouded' ? '' : '/servers/'.$id);
    }
    public function catalog(Principal $actor): array
    {
        $this->policy->require($actor, 'catalog.read'); $settings = $this->settings(); $profiles = [];
        foreach ($settings->servers as $profile) {
            if (!$actor->allows('status.read', $profile->id)) { continue; }
            $profiles[] = $actor->allows('config.read', $profile->id) ? $profile : (object) ['id' => $profile->id, 'label' => $profile->label, 'game' => $profile->game];
        }
        return ['servers' => $profiles, 'games' => $this->games->entries($settings->catalog_games ?? [])];
    }
    public function register(Principal $actor, \stdClass $profile): array
    {
        $this->policy->require($actor, 'catalog.write');
        if ($actor->serverIds !== null) { throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Neue Profile benötigen eine Freigabe ohne Profilbegrenzung.'); }
        if (array_diff(array_keys(get_object_vars($profile)), ['id', 'game', 'label', 'server_directory', 'config_path', 'launcher_script', 'log_directory', 'steam_manifest', 'steam_branch', 'connection'])) { throw new \InvalidArgumentException('Unbekannte Profilfelder.'); }
        $this->validateProfile($profile);
        return $this->mutate($actor, function (\stdClass $settings) use ($profile): \stdClass { $settings->servers[] = $profile; return $this->validate($settings); });
    }
    public function saveConnection(Principal $actor, string $id, mixed $host): array
    {
        $this->policy->require($actor, 'catalog.write', $id); $host = self::publicHost($host);
        return $this->mutate($actor, function (\stdClass $settings) use ($id, $host): \stdClass {
            foreach ($settings->servers as $profile) { if ($profile->id === $id) { $profile->connection = (object) ['public_host' => $host]; $this->validateProfile($profile); return $settings; } }
            throw new \InvalidArgumentException('Dieses Serverprofil existiert nicht.');
        }, $id);
    }
    private function mutate(Principal $actor, callable $change, ?string $id = null): array
    {
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('registry'); $lock->acquire(true);
        try {
            $this->policy->require($actor, 'catalog.write', $id); $settings = $change($this->settings()); $settings->schema_version = 2;
            PrivateFiles::write($this->runtime->directory.'/manager.local.json', PrivateFiles::json($settings));
            return ['message' => 'Profilmetadaten gespeichert. Spieldateien und Welten wurden nicht verändert.'];
        } finally { $lock->release(); }
    }
    public function import(string $source): array
    {
        $this->runtime->assertInitialized();
        $bytes = PrivateFiles::read($source); $settings = $this->validate(PrivateFiles::object($bytes));
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('registry'); $lock->acquire(true);
        try {
            $target = $this->runtime->directory.'/manager.local.json';
            if (file_exists($target) || is_link($target)) { throw new \DomainException('Bestehende Profile werden nicht überschrieben.'); }
            PrivateFiles::write($target, $bytes);
            return ['profiles' => count($settings->servers), 'sha256' => hash('sha256', $bytes)];
        } finally { $lock->release(); }
    }
}
