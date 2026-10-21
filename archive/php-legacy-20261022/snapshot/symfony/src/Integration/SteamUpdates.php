<?php
declare(strict_types=1);
namespace App\Integration;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SteamUpdates
{
    public const INTERVAL = 1800;
    public function __construct(private readonly SliceRuntime $runtime, private readonly SteamInstallation $installed,
        private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly ManagerSettings $settings, private readonly SteamProvider $provider, private readonly NativePaths $paths) {}
    private function path(): string { return $this->runtime->directory.'/data/steam-updates.json'; }
    private function entries(): \stdClass
    {
        try { $cache = PrivateFiles::object(PrivateFiles::read($this->path())); }
        catch (\Throwable) { return new \stdClass(); }
        return ($cache->schema_version ?? null) === 1 && ($cache->entries ?? null) instanceof \stdClass ? $cache->entries : new \stdClass();
    }
    private function entry(string $id): \stdClass { $entry = $this->entries()->{$id} ?? null; return $entry instanceof \stdClass ? $entry : new \stdClass(); }
    private function save(string $id, \stdClass $entry): void
    {
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('steam-cache'); $lock->acquire(true);
        try {
            $entries = $this->entries(); $entries->{$id} = $entry; PrivateFiles::directory(dirname($this->path()));
            PrivateFiles::write($this->path(), PrivateFiles::json((object) ['schema_version' => 1, 'entries' => $entries]));
        } finally { $lock->release(); }
    }
    private static function age(mixed $time): float
    {
        if (!is_string($time)) { return INF; }
        try { return microtime(true) - (float) \App\Community\ChronicleTime::parse($time)->format('U.u'); } catch (\Throwable) { return INF; }
    }
    public function check(string $id): array
    {
        $profile = $this->registry->get($id); $entry = $this->entry($id);
        $age = self::age($entry->last_attempt ?? null);
        if ($age >= 0 && $age < 60) { return ['message' => 'Die letzte Steam-Prüfung liegt weniger als eine Minute zurück.', 'update' => $this->view($id)]; }
        $entry->last_attempt = gmdate('Y-m-d\TH:i:s\Z');
        // Reserve the attempt before I/O, so a failed worker cannot trigger a query storm.
        $this->save($id, $entry);
        $app = $this->games->schema($profile->game)->steam_server_app_id;
        try { $branch = $this->installed->read($id)['branch']; }
        catch (\Throwable) { $branch = SteamKeyValues::branch($profile->steam_branch ?? 'public'); }
        try {
            $build = $this->provider->query($app, $branch);
            if (!SteamKeyValues::build($build)) { throw new \DomainException('Steam hat keine gültige Build-ID geliefert.'); }
            $entry = (object) ['app_id' => $app, 'branch' => $branch, 'build' => $build,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'last_attempt' => $entry->last_attempt, 'error' => null];
        } catch (\Throwable) { $entry->error = 'Steam-Metadaten konnten nicht bestätigt werden. Lokale Einrichtung und Verbindung prüfen.'; }
        $this->save($id, $entry);
        if ($entry->error !== null) { throw new \DomainException($entry->error); }
        $view = $this->view($id); return ['message' => 'Steam-Version geprüft. '.$view['label'].'.', 'update' => $view];
    }
    public function view(string $id, ?array $status = null): array
    {
        $profile = $this->registry->get($id); $schema = $this->games->schema($profile->game); $entry = $this->entry($id);
        $branch = SteamKeyValues::branch($profile->steam_branch ?? 'public');
        $result = ['app_id' => $schema->steam_server_app_id, 'branch' => $branch, 'state' => 'unknown', 'label' => 'Unbekannt',
            'installed_build' => null, 'available_build' => null, 'checked_at' => null, 'local_source' => 'Steam-Appmanifest',
            'remote_source' => 'SteamCMD · anonym', 'message' => 'Noch keine erfolgreiche Steam-Prüfung.', 'version' => null,
            'automatic' => ($this->settings->read()->automatic_update_checks ?? true) === true, 'version_source' => null,
            'process_state' => 'unknown', 'process_message' => 'Laufender Build nicht ermittelt.'];
        try { $local = $this->installed->read($id); $branch = $local['branch']; $result['branch'] = $branch; $result['installed_build'] = $local['build']; }
        catch (\Throwable) { $local = null; $result['message'] = 'Das lokale Steam-Appmanifest ist nicht eindeutig lesbar.'; }
        if ($profile->game === 'enshrouded') {
            try {
                $text = PrivateFiles::read($this->paths->local($profile->server_directory).'/enshrouded_server_readme.txt', SteamKeyValues::LIMIT);
                if (preg_match('/^Version:\s*([0-9]+(?:\.[0-9]+){3})\s*$/m', $text, $match)) { $result['version'] = $match[1]; $result['version_source'] = 'Mitgelieferte Serveranleitung'; }
            } catch (\Throwable) {}
        }
        if (($entry->app_id ?? null) === $schema->steam_server_app_id && ($entry->branch ?? null) === $branch) {
            $age = self::age($entry->checked_at ?? null);
            if (SteamKeyValues::build($entry->build ?? null) && is_finite($age)) {
                $result['available_build'] = $entry->build; $result['checked_at'] = $entry->checked_at;
                if ($local && $local['complete']) {
                    $result['state'] = $local['build'] === $entry->build ? 'current' : 'update_available';
                    $result['label'] = $result['state'] === 'current' ? 'Aktuell' : 'Update verfügbar';
                    $result['message'] = 'Installierter und angebotener Build für denselben Branch verglichen.';
                }
                if ($age > self::INTERVAL || $age < -60 || !empty($entry->error)) {
                    $result['state'] = 'stale'; $result['label'] = 'Veraltet'; $result['message'] = 'Steam-Befund nicht mehr aktuell. Bitte erneut prüfen.';
                }
            }
        }
        if ($local && ($local['busy'] || !$local['complete'])) {
            $result['state'] = $local['busy'] ? 'updating' : 'unknown'; $result['label'] = $local['busy'] ? 'Installation/Update läuft' : 'Unbekannt';
            $result['message'] = 'Steam hat die Installation noch nicht als vollständig abgeschlossen bestätigt.';
        }
        if ($status !== null && ($status['running'] ?? null) === false) { $result['process_state'] = 'stopped'; $result['process_message'] = 'Kein Serverprozess aktiv.'; }
        elseif ($status !== null && ($status['running'] ?? null) === true && $local) {
            foreach ($status['processes'] ?? [] as $process) {
                try {
                    $started = (float) (new \DateTimeImmutable($process['started']))->format('U.u');
                    $changed = max($local['updated'], @filemtime($this->paths->local($profile->server_directory).'/'.$schema->executable) ?: 0);
                    if ($changed > $started) { $result['process_state'] = 'restart_needed'; $result['process_message'] = 'Dateien seit Prozessstart aktualisiert. Laufender Build unbekannt; kontrollierten Neustart einplanen.'; }
                } catch (\Throwable) {}
            }
        }
        return $result;
    }
}
