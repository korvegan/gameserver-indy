<?php
declare(strict_types=1);
namespace App\Community;

use App\Game\GameCatalog;
use App\Storage\PrivateFiles;

/** Explicit versioned copy package. Never activates it or writes to the source. */
final class ChronicleSnapshot
{
    private const TABLES = [
        'jobs' => ['id', 'server_id', 'operation', 'actor_id', 'state', 'message', 'created_at', 'updated_at', 'result'],
        'community_events' => ['id', 'revision', 'game_id', 'instance_id', 'payload', 'recorded_at'],
        'scenes' => ['id', 'event_id', 'position', 'template'],
        'deliveries' => ['event_id', 'revision', 'target', 'state', 'remote_id'],
        'community_media' => ['id', 'content', 'width', 'height'],
    ];
    public function copy(string $sourcePath, string $destination): array
    {
        if (!is_file($sourcePath) || is_link($sourcePath) || !is_dir(dirname($destination)) || file_exists($destination) || is_link($destination)) { throw new \DomainException('Lokale Plattformkopie und einen neuen privaten Zielordner wählen.'); }
        $source = new \SQLite3($sourcePath, SQLITE3_OPEN_READONLY); $source->enableExceptions(true); $source->busyTimeout(5000);
        $target = null; $created = false;
        try {
            $source->exec('BEGIN'); $before = $this->inspect($source);
            if (!mkdir($destination, 0700)) { throw new \RuntimeException('Chronikpaket nicht anlegbar.'); } $created = true;
            $target = new \SQLite3($destination.'/source.sqlite3'); $target->enableExceptions(true);
            if (!$source->backup($target) || $this->inspect($target) !== $before) { throw new \RuntimeException('Chroniksnapshot stimmt nicht mit der Quelle überein.'); }
            $target->close(); $target = null; $source->exec('ROLLBACK');
            $snapshot = new \SQLite3($destination.'/source.sqlite3', SQLITE3_OPEN_READONLY); $snapshot->enableExceptions(true);
            try {
                $target = new \SQLite3($destination.'/platform.sqlite3'); $target->enableExceptions(true);
                if (!$snapshot->backup($target)) { throw new \RuntimeException('Arbeitskopie nicht anlegbar.'); }
            } finally { $snapshot->close(); }
            if ($before['schema'] === 1) {
                $target->exec('BEGIN IMMEDIATE');
                try { $target->exec(PlatformDatabase::MEDIA); $target->exec('PRAGMA user_version=2'); $target->exec('COMMIT'); }
                catch (\Throwable $error) { $target->exec('ROLLBACK'); throw $error; }
            }
            $after = $this->inspect($target); $target->close(); $target = null;
            foreach ($before['tables'] as $table => $evidence) { if (($after['tables'][$table] ?? null) !== $evidence) { throw new \RuntimeException('Chronikdaten wurden bei der Kopiermigration verändert.'); } }
            $manifest = ['format' => 'indy-chronicle-migration', 'version' => 1, 'source_schema' => $before['schema'], 'target_schema' => 2,
                'tables' => $after['tables'], 'hashes' => []];
            foreach (['source.sqlite3', 'platform.sqlite3'] as $name) { chmod($destination.'/'.$name, 0600); $manifest['hashes'][$name] = hash_file('sha256', $destination.'/'.$name); }
            PrivateFiles::write($destination.'/.indy-chronicle-import.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return $manifest;
        } catch (\Throwable $error) {
            $target?->close();
            if ($created) {
                foreach (['source.sqlite3', 'platform.sqlite3', 'platform.sqlite3-journal', '.indy-chronicle-import.json'] as $name) { if (is_file($destination.'/'.$name)) { unlink($destination.'/'.$name); } }
                rmdir($destination);
            }
            throw $error;
        } finally { $source->close(); }
    }
    private function inspect(\SQLite3 $db): array
    {
        $version = (int) $db->querySingle('PRAGMA user_version');
        if (!in_array($version, [1, 2], true) || $db->querySingle('PRAGMA quick_check') !== 'ok'
            || $db->querySingle('PRAGMA foreign_key_check') !== null
            || $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('view','trigger')") != 0) { throw new \DomainException('Plattformversion, Struktur oder Integrität nicht unterstützt.'); }
        $tables = [];
        foreach (self::TABLES as $table => $columns) {
            if ($table === 'community_media' && $version === 1) { continue; }
            $result = $db->query('PRAGMA table_info('.$table.')'); $actual = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $actual[] = $row['name']; } $result->finalize();
            if ($actual !== $columns) { throw new \DomainException('Plattformtabellen passen nicht zur erwarteten Version.'); }
            $hash = hash_init('sha256'); $count = 0; $rows = $db->query('SELECT * FROM '.$table.' ORDER BY rowid');
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                if ($table === 'community_events') {
                    $event = json_decode($row['payload'], flags: JSON_THROW_ON_ERROR);
                    if (!$event instanceof \stdClass || ($event->id ?? null) !== $row['id'] || ($event->revision ?? null) !== $row['revision']
                        || ($event->game_id ?? null) !== $row['game_id'] || ($event->instance_id ?? null) !== $row['instance_id']
                        || !GameCatalog::identifier($row['id']) || !is_int($row['revision']) || $row['revision'] < 1
                        || !is_string($event->title ?? null) || !is_array($event->member_ids ?? null) || !is_string($event->badge_id ?? null)
                        || (isset($event->media_id) && (!is_string($event->media_id) || !preg_match('/^[a-f0-9]{64}$/D', $event->media_id)))) { throw new \DomainException('Chronikreferenzen sind beschädigt.'); }
                    if (isset($event->media_id)) {
                        if ($version !== 2) { throw new \DomainException('Screenshotreferenz ohne Medienschema.'); }
                        $query = $db->prepare('SELECT 1 FROM community_media WHERE id=:id'); $query->bindValue(':id', $event->media_id, SQLITE3_TEXT); $found = $query->execute();
                        $exists = $found->fetchArray(); $found->finalize(); $query->close(); if (!$exists) { throw new \DomainException('Eine Screenshotreferenz fehlt.'); }
                    }
                } elseif ($table === 'community_media') {
                    if (!hash_equals($row['id'], hash('sha256', $row['content'])) || array_slice(ChronicleValues::jpeg($row['content']), 1) !== [$row['width'], $row['height']]) { throw new \DomainException('Bildinhalt, Abmessungen oder Prüfsumme stimmen nicht.'); }
                } elseif ($table === 'scenes') {
                    if (!is_int($row['position']) || $row['position'] < 0 || !is_string($row['template']) || $row['template'] === '') { throw new \DomainException('Ungültige feste Szenenzuordnung.'); }
                } elseif ($table === 'deliveries' && !in_array($row['state'], ['pending', 'sending', 'sent', 'failed', 'uncertain', 'superseded'], true)) { throw new \DomainException('Unbekannter Versandzustand.'); }
                foreach ($row as $value) { $bytes = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR); hash_update($hash, get_debug_type($value).':'.strlen($bytes).':'.$bytes); }
                ++$count;
            }
            $rows->finalize(); $tables[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
        }
        if ($db->querySingle('SELECT COUNT(*) FROM community_events e LEFT JOIN scenes s ON s.event_id=e.id WHERE s.id IS NULL') != 0
            || $db->querySingle('SELECT COUNT(*) FROM scenes s WHERE NOT EXISTS (SELECT 1 FROM community_events e WHERE e.id=s.event_id)') != 0) { throw new \DomainException('Ein Ereignis oder eine Szene besitzt keine dauerhafte Zuordnung.'); }
        return ['schema' => $version, 'tables' => $tables];
    }
}
