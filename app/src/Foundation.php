<?php
declare(strict_types=1);

namespace IndyNext;

enum Role: string
{
    case Administrator = 'administrator';
    case Family = 'family';

    public function canManageAccess(): bool
    {
        return $this === self::Administrator;
    }
}

/** EP01 has no native adapter: every database is a disposable fixture. */
final class FixtureStore
{
    private \PDO $db;

    public static function create(): string
    {
        $directory = sys_get_temp_dir().'/indy-next-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('fixture_create_failed');
        }
        file_put_contents($directory.'/fixture', "indy-next-v1\n");
        $db = new \PDO('sqlite:'.$directory.'/state.sqlite');
        chmod($directory.'/fixture', 0600);
        chmod($directory.'/state.sqlite', 0600);
        $db->exec("CREATE TABLE servers (
            id TEXT PRIMARY KEY CHECK (id IN ('enshrouded', 'aska')),
            process TEXT NOT NULL CHECK (process IN ('unknown', 'stopped', 'running')),
            ready INTEGER CHECK (ready IS NULL OR (process = 'running' AND ready IN (0, 1))),
            observed_at TEXT,
            refresh_after INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("INSERT INTO servers (id, process) VALUES ('enshrouded', 'unknown'), ('aska', 'unknown')");
        $db->exec("CREATE TABLE operations (
            id TEXT PRIMARY KEY,
            server TEXT NOT NULL REFERENCES servers(id),
            action TEXT NOT NULL CHECK (action IN ('start', 'stop')),
            actor TEXT NOT NULL,
            state TEXT NOT NULL CHECK (state IN ('pending', 'running', 'succeeded', 'failed', 'cancelled', 'unknown')),
            actor_revision INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE UNIQUE INDEX one_active_operation ON operations(server)
            WHERE state IN ('pending', 'running', 'unknown')");
        return $directory;
    }

    public function __construct(string $directory)
    {
        $parent = realpath(sys_get_temp_dir());
        $resolved = realpath($directory);
        if ($resolved === false || is_link($directory) || dirname($resolved) !== $parent
            || !preg_match('/^indy-next-[a-f0-9]{24}$/D', basename($resolved))
            || is_link($resolved.'/fixture') || is_link($resolved.'/state.sqlite')
            || !is_file($resolved.'/state.sqlite')
            || @file_get_contents($resolved.'/fixture') !== "indy-next-v1\n") {
            throw new \RuntimeException('isolated_fixture_required');
        }
        $this->db = new \PDO('sqlite:'.$resolved.'/state.sqlite', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 2000');
        $ids = $this->db->query('SELECT id FROM servers ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids !== ['aska', 'enshrouded']) {
            throw new \RuntimeException('incomplete_fixture');
        }
        $this->db->query('SELECT observed_at, refresh_after FROM servers LIMIT 0');
        $this->db->query('SELECT id, server, action, actor, state, actor_revision, error, created_at, updated_at FROM operations LIMIT 0');
    }

    public function snapshot(): array
    {
        $servers = $this->db->query('SELECT id, process, ready, observed_at FROM servers ORDER BY id')->fetchAll();
        foreach ($servers as &$server) {
            $server['ready'] = $server['ready'] === null ? null : (bool) $server['ready'];
        }
        return $servers;
    }

    public function observe(string $server, string $process, ?bool $ready, ?string $at = null): void
    {
        self::server($server);
        if (!in_array($process, ['unknown', 'stopped', 'running'], true)
            || ($ready !== null && $process !== 'running')) {
            throw new \InvalidArgumentException('invalid_observation');
        }
        $at = $at === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            : new \DateTimeImmutable(preg_replace('/(\.\d{6})\dZ$/', '$1Z', $at));
        $stamp = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $query = $this->db->prepare('UPDATE servers SET process = ?, ready = ?, observed_at = ?
            WHERE id = ? AND (observed_at IS NULL OR observed_at <= ?)');
        $query->execute([$process, $ready === null ? null : (int) $ready, $stamp, $server, $stamp]);
    }

    public function reserve(string $server, string $action, string $actor, Role $role, int $revision = 0): array
    {
        self::server($server);
        if (!in_array($action, ['start', 'stop'], true) || trim($actor) === '') {
            throw new \InvalidArgumentException('invalid_operation');
        }
        // Both enum roles may start/stop. Verified identity is supplied by EP04, never HTTP input.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->db->prepare("SELECT * FROM operations WHERE server = ? AND state IN ('pending', 'running', 'unknown')");
            $query->execute([$server]);
            $active = $query->fetch();
            if ($active !== false) {
                if ($active['state'] === 'unknown' || $active['action'] !== $action) {
                    throw new \RuntimeException('server_busy');
                }
                $this->db->exec('COMMIT');
                return $active;
            }
            $operation = ['id' => bin2hex(random_bytes(16)), 'server' => $server,
                'action' => $action, 'actor' => $actor, 'state' => 'pending', 'actor_revision' => $revision,
                'error' => '', 'created_at' => (int) (microtime(true) * 1000), 'updated_at' => (int) (microtime(true) * 1000)];
            $query = $this->db->prepare('INSERT INTO operations (id, server, action, actor, state, actor_revision, error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute(array_values($operation));
            $this->db->exec('COMMIT');
            return $operation;
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function transition(string $id, string $state, string $error = ''): void
    {
        $from = match ($state) {
            'running', 'cancelled' => ['pending'],
            'succeeded', 'failed' => ['running', 'unknown'],
            'unknown' => ['running'],
            default => throw new \InvalidArgumentException('invalid_transition'),
        };
        $slots = implode(', ', array_fill(0, count($from), '?'));
        $query = $this->db->prepare("UPDATE operations SET state = ?, error = ?, updated_at = ? WHERE id = ? AND state IN ($slots)");
        $query->execute([$state, $error, (int) (microtime(true) * 1000), $id, ...$from]);
        if ($query->rowCount() !== 1) {
            throw new \RuntimeException('invalid_transition');
        }
    }

    public function operation(string $id): array
    {
        $query = $this->db->prepare('SELECT * FROM operations WHERE id = ?');
        $query->execute([$id]);
        $operation = $query->fetch();
        if ($operation === false) { throw new \RuntimeException('operation_missing'); }
        return $operation;
    }

    public function latest(string $server): ?array
    {
        self::server($server);
        $query = $this->db->prepare('SELECT * FROM operations WHERE server = ? ORDER BY created_at DESC, rowid DESC LIMIT 1');
        $query->execute([$server]);
        return $query->fetch() ?: null;
    }

    public function claimRefresh(string $server): bool
    {
        self::server($server);
        $now = (int) (microtime(true) * 1000);
        $query = $this->db->prepare('UPDATE servers SET refresh_after = ? WHERE id = ? AND refresh_after <= ?');
        $query->execute([$now + 100000, $server, $now]);
        return $query->rowCount() === 1;
    }

    public function releaseRefresh(string $server): void
    {
        $query = $this->db->prepare('UPDATE servers SET refresh_after = ? WHERE id = ?');
        $query->execute([(int) (microtime(true) * 1000) + 5000, $server]);
    }

    public function recover(string $server): void
    {
        self::server($server);
        $now = (int) (microtime(true) * 1000);
        // Late workers cannot begin a cancelled pending operation; running work is never retried.
        $query = $this->db->prepare("UPDATE operations SET state = 'cancelled', error = 'executor_unavailable', updated_at = ?
            WHERE server = ? AND state = 'pending' AND updated_at < ?");
        $query->execute([$now, $server, $now - 30000]);
        $query = $this->db->prepare("UPDATE operations SET state = 'unknown', error = 'outcome_unresolved', updated_at = ?
            WHERE server = ? AND state = 'running' AND updated_at < ?");
        $query->execute([$now, $server, $now - 105000]);
    }

    private static function server(string $server): void
    {
        if (!in_array($server, ['enshrouded', 'aska'], true)) {
            throw new \InvalidArgumentException('unknown_server');
        }
    }
}
