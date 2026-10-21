<?php
declare(strict_types=1);
namespace App\Runtime;

use Doctrine\DBAL\Connection;

/** Small durable inbox for verified game events, never a copy of raw game output. */
final class RuntimeEvents
{
    private bool $initialized = false;
    public function __construct(private readonly Connection $db, private readonly RuntimeSignals $signals) {}
    private function ready(): void
    {
        if ($this->initialized) { return; }
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS runtime_events (
            sequence INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT NOT NULL UNIQUE,
            server_id TEXT NOT NULL, type TEXT NOT NULL, created_at TEXT NOT NULL,
            handled INTEGER NOT NULL DEFAULT 0)');
        $this->initialized = true;
    }
    public function append(string $serverId, string $key, string $type): void
    {
        if (!in_array($type, ['session.ready', 'session.closed'], true)) { throw new \InvalidArgumentException('Unbekanntes Spielereignis.'); }
        $this->ready();
        $changed = $this->db->executeStatement('INSERT OR IGNORE INTO runtime_events (event_key,server_id,type,created_at) VALUES (?,?,?,?)',
            [$key, $serverId, $type, gmdate('Y-m-d\TH:i:s\Z')]);
        if ($changed) { $this->signals->publish('game-event', $serverId); }
    }
    public function pending(): array
    {
        $this->ready();
        return $this->db->fetchAllAssociative('SELECT * FROM runtime_events WHERE handled=0 ORDER BY sequence LIMIT 100');
    }
    public function get(int $sequence): array
    {
        $this->ready();
        return $this->db->fetchAssociative('SELECT * FROM runtime_events WHERE sequence=?', [$sequence])
            ?: throw new \InvalidArgumentException('Spielereignis nicht gefunden.');
    }
    public function acknowledge(int $sequence): void
    {
        $this->ready(); $this->db->update('runtime_events', ['handled'=>1], ['sequence'=>$sequence]);
    }
    public function recent(string $id): array
    {
        $this->ready();
        return $this->db->fetchAllAssociative('SELECT type,created_at FROM runtime_events WHERE server_id=? ORDER BY sequence DESC LIMIT 30', [$id]);
    }
}
