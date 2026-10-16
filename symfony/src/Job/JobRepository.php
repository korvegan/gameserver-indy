<?php
declare(strict_types=1);

namespace App\Job;

use App\Access\Principal;
use App\Runtime\SliceRuntime;
use Doctrine\DBAL\Connection;

/** Operation ledger is separate from Messenger delivery/acknowledgement. */
final class JobRepository
{
    private bool $configured = false;

    public function __construct(private readonly Connection $db, private readonly SliceRuntime $runtime) {}

    public function initialize(): void
    {
        $this->ready();
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS slice_jobs (
            sequence INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE, server_id TEXT NOT NULL, operation TEXT NOT NULL,
            state TEXT NOT NULL, message TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        if (!in_array('revision', array_column($this->db->fetchAllAssociative('PRAGMA table_info(slice_jobs)'), 'name'), true)) {
            $this->db->executeStatement('ALTER TABLE slice_jobs ADD COLUMN revision INTEGER NOT NULL DEFAULT 1');
        }
        if (!in_array('actor', array_column($this->db->fetchAllAssociative('PRAGMA table_info(slice_jobs)'), 'name'), true)) {
            $this->db->executeStatement('ALTER TABLE slice_jobs ADD COLUMN actor TEXT NOT NULL DEFAULT '
                .$this->db->quote((new Principal('local-owner', 'owner'))->claim()));
        }
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS slice_events (
            sequence INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL)');
        chmod($this->runtime->directory.'/slice.sqlite', 0600);
    }

    public function byRequest(string $requestId): ?array
    {
        $this->ready();
        return $this->db->fetchAssociative('SELECT * FROM slice_jobs WHERE request_id=?', [$requestId]) ?: null;
    }

    public function get(string $id): array
    {
        $this->ready();
        return $this->db->fetchAssociative('SELECT * FROM slice_jobs WHERE id=?', [$id])
            ?: throw new \InvalidArgumentException('Auftrag nicht gefunden.');
    }

    public function recent(?array $serverIds = null): array
    {
        $this->ready();
        if ($serverIds === []) { return []; }
        $where = $serverIds === null ? '' : ' WHERE server_id IN ('.implode(',', array_fill(0, count($serverIds), '?')).')';
        return $this->db->fetchAllAssociative('SELECT * FROM slice_jobs'.$where.' ORDER BY sequence DESC LIMIT 30', $serverIds ?? []);
    }

    public function snapshot(?array $serverIds = null): array
    {
        return $this->transaction(fn () => ['jobs' => $this->recent($serverIds),
            'cursor' => (int) $this->db->fetchOne('SELECT COALESCE(MAX(sequence),0) FROM slice_events')]);
    }

    public function pendingCount(?string $serverId = null): int
    {
        $this->ready();
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM slice_jobs WHERE state IN ('queued','running')"
            .($serverId === null ? '' : ' AND server_id=?'), $serverId === null ? [] : [$serverId]);
    }

    public function insert(string $id, string $requestId, string $serverId, string $operation, Principal $actor): void
    {
        $this->ready();
        $time = gmdate('Y-m-d\TH:i:s\Z');
        $this->db->insert('slice_jobs', ['id' => $id, 'request_id' => $requestId, 'server_id' => $serverId,
            'operation' => $operation, 'state' => 'queued', 'message' => 'Wartet auf Ausführung.',
            'actor' => $actor->claim(),
            'created_at' => $time, 'updated_at' => $time]);
        $this->event($id);
    }

    public function transaction(callable $callback): mixed
    {
        $this->ready();
        return $this->db->transactional($callback);
    }

    public function next(string $serverId): ?array
    {
        $this->ready();
        return $this->db->fetchAssociative("SELECT * FROM slice_jobs WHERE server_id=? AND state='queued' ORDER BY sequence LIMIT 1", [$serverId]) ?: null;
    }

    public function hasRunning(string $serverId): bool
    {
        $this->ready();
        return (bool) $this->db->fetchOne("SELECT 1 FROM slice_jobs WHERE server_id=? AND state='running'", [$serverId]);
    }

    public function transition(string $id, string $state, string $message): void
    {
        $this->transaction(function () use ($id, $state, $message): void {
            $this->db->executeStatement('UPDATE slice_jobs SET state=?,message=?,updated_at=?,revision=revision+1 WHERE id=?',
                [$state, $message, gmdate('Y-m-d\TH:i:s\Z'), $id]);
            $this->event($id);
        });
    }

    public function failWithFollowers(string $id, string $state, string $message): void
    {
        $serverId = $this->get($id)['server_id'];
        $this->transaction(function () use ($id, $state, $message, $serverId): void {
            $this->transition($id, $state, $message);
            $this->interrupt($serverId, ['queued'], 'Ein vorheriger Auftrag ist fehlgeschlagen oder unklar. Dieser Befehl wurde nicht ausgeführt. Bitte neu beauftragen.');
        });
    }

    public function recover(string $serverId): void
    {
        $this->transaction(fn () => $this->interrupt($serverId, ['queued', 'running'],
            'Worker wurde unterbrochen. Ausgang unbekannt; Prozessstatus prüfen. Kein automatischer Wiederholungsversuch.'));
    }

    public function events(int $after): array
    {
        $this->ready();
        return $this->db->fetchAllAssociative('SELECT sequence,payload FROM slice_events WHERE sequence>? ORDER BY sequence LIMIT 100', [$after]);
    }

    private function interrupt(string $serverId, array $states, string $message): void
    {
        foreach ($this->db->fetchAllAssociative('SELECT id,state FROM slice_jobs WHERE server_id=?', [$serverId]) as $job) {
            if (in_array($job['state'], $states, true)) {
                $this->transition($job['id'], 'interrupted', $message);
            }
        }
    }

    private function event(string $id): void
    {
        $this->db->insert('slice_events', ['payload' => json_encode(self::public($this->get($id)), JSON_THROW_ON_ERROR)]);
    }

    public static function public(array $job): array { unset($job['actor']); return $job; }

    private function ready(): void
    {
        $this->runtime->assertInitialized();
        if (!$this->configured) {
            $this->db->executeStatement('PRAGMA busy_timeout=5000');
            $this->configured = true;
        }
    }
}
