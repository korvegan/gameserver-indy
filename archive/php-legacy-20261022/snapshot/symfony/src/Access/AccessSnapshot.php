<?php
declare(strict_types=1);
namespace App\Access;

/** Explicit read-only copy package. Does not activate it or alter a running manager. */
final class AccessSnapshot
{
    public function copy(string $sourceDirectory, string $destination): array
    {
        foreach (['access.sqlite3', 'session-key'] as $file) {
            if (!is_file($sourceDirectory.'/'.$file) || is_link($sourceDirectory.'/'.$file)) {
                throw new \DomainException('Vollständige lokale Zugangskopie erforderlich.');
            }
        }
        $settingsPath = $sourceDirectory.'/access-settings.json';
        if (is_link($settingsPath)) { throw new \DomainException('Verknüpfte Zugangseinstellungen abgewiesen.'); }
        $settings = is_file($settingsPath) ? file_get_contents($settingsPath) : '{}';
        AccessStore::configuration(json_decode($settings, true, flags: JSON_THROW_ON_ERROR));
        $key = file_get_contents($sourceDirectory.'/session-key');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $key)) { throw new \DomainException('Sitzungsschlüssel ungültig; nicht ersetzen.'); }
        if (file_exists($destination) || is_link($destination) || !is_dir(dirname($destination))) {
            throw new \DomainException('Eine neue Zieldateiablage mit vorhandenem Elternordner wählen.');
        }
        $source = new \SQLite3($sourceDirectory.'/access.sqlite3', SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        $target = null; $created = false;
        try {
            $source->exec('BEGIN');
            if ((int) $source->querySingle('PRAGMA user_version') !== 1 || $source->querySingle('PRAGMA quick_check') !== 'ok') {
                throw new \DomainException('Zugangsdatenbankversion oder Integrität nicht unterstützt.');
            }
            foreach (['users' => ['id', 'login', 'name', 'role', 'servers', 'enabled', 'revision', 'created_at', 'updated_at'],
                'audit' => ['id', 'at', 'actor', 'operation', 'resource', 'outcome']] as $table => $columns) {
                $schema = $source->query('PRAGMA table_info('.$table.')'); $names = [];
                while ($column = $schema->fetchArray(SQLITE3_ASSOC)) { $names[] = $column['name']; }
                $schema->finalize();
                if ($names !== $columns) { throw new \DomainException('Zugangsdatenbankstruktur nicht unterstützt.'); }
            }
            $rows = $source->query('SELECT id,login,name,role,servers,enabled,revision FROM users'); $count = 0;
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                AccessStore::login($row['login']);
                $ids = $row['servers'] === null ? null : json_decode($row['servers'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_string($row['id']) || $row['id'] === '' || !is_string($row['name'])
                    || !in_array($row['role'], ['owner', 'family', 'viewer'], true) || !in_array($row['enabled'], [0, 1], true)
                    || !is_int($row['revision']) || $row['revision'] < 1
                    || ($row['role'] === 'owner' ? $ids !== null : (!is_array($ids) || !array_is_list($ids)
                        || array_filter($ids, fn ($id) => !is_string($id))))) {
                    throw new \DomainException('Freigabestruktur nicht unterstützt.');
                }
                ++$count;
            }
            $rows->finalize();
            if (!mkdir($destination, 0700)) { throw new \RuntimeException('Ziel nicht angelegt.'); } $created = true;
            $target = new \SQLite3($destination.'/access.sqlite3');
            $target->enableExceptions(true);
            if (!$source->backup($target) || $target->querySingle('PRAGMA quick_check') !== 'ok') {
                throw new \RuntimeException('Konsistente Zugangskopie fehlgeschlagen.');
            }
            $target->close(); $target = null; $source->exec('ROLLBACK');
            foreach (['session-key' => $key, 'access-settings.json' => $settings] as $file => $content) {
                if (file_put_contents($destination.'/'.$file, $content, LOCK_EX) !== strlen($content)) { throw new \RuntimeException('Kopie nicht gespeichert.'); }
            }
            $hashes = [];
            foreach (['access.sqlite3', 'access-settings.json', 'session-key'] as $file) {
                chmod($destination.'/'.$file, 0600); $hashes[$file] = hash_file('sha256', $destination.'/'.$file);
            }
            $manifest = ['schema_version' => 1, 'users' => $count, 'hashes' => $hashes];
            $content = json_encode($manifest, JSON_THROW_ON_ERROR);
            if (file_put_contents($destination.'/.indy-access-import.json', $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException('Übernahmenachweis nicht gespeichert.');
            }
            chmod($destination.'/.indy-access-import.json', 0600);
            return $manifest;
        } catch (\Throwable $error) {
            $target?->close();
            if ($created) {
                foreach (['access.sqlite3', 'access-settings.json', 'session-key', '.indy-access-import.json'] as $file) {
                    if (is_file($destination.'/'.$file)) { unlink($destination.'/'.$file); }
                }
                rmdir($destination);
            }
            throw $error;
        } finally { $source->close(); }
    }
}
