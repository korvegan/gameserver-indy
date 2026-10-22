<?php
declare(strict_types=1);

namespace IndyNext;

final readonly class Principal
{
    public function __construct(public string $id, public string $login, public string $name,
        public Role $role, public int $revision, public array $servers = ['aska', 'enshrouded']) {}

    public static function owner(): self
    {
        return new self('local-owner', 'local-owner', 'Lokaler Besitzer', Role::Administrator, 0);
    }
}

/** Separate from game state: restoring a world must never restore an old grant. */
final class AccessStore
{
    private \PDO $db;
    private string $key;

    public static function create(string $directory): void
    {
        new FixtureStore($directory);
        foreach (['access.sqlite', 'csrf-key'] as $file) {
            $handle = fopen($directory.'/'.$file, 'x');
            if ($handle === false) { throw new \RuntimeException('access_create_failed'); }
            try {
                chmod($directory.'/'.$file, 0600);
                if ($file === 'csrf-key' && fwrite($handle, random_bytes(32)) !== 32) {
                    throw new \RuntimeException('access_create_failed');
                }
            } finally { fclose($handle); }
        }
        $db = new \PDO('sqlite:'.$directory.'/access.sqlite');
        $db->exec("CREATE TABLE grants (
            id TEXT PRIMARY KEY, login TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('administrator','family')),
            enabled INTEGER NOT NULL CHECK(enabled IN (0,1)), revision INTEGER NOT NULL,
            servers TEXT NOT NULL
        )");
    }

    public function __construct(string $directory)
    {
        new FixtureStore($directory);
        foreach (['access.sqlite', 'csrf-key'] as $file) {
            $path = $directory.'/'.$file;
            if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600) {
                throw new \RuntimeException('private_access_store_required');
            }
        }
        $this->key = file_get_contents($directory.'/csrf-key');
        if (strlen($this->key) !== 32) { throw new \RuntimeException('invalid_csrf_key'); }
        $this->db = new \PDO('sqlite:'.$directory.'/access.sqlite', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout = 2000');
        $this->db->query('SELECT id,login,name,role,enabled,revision,servers FROM grants LIMIT 0');
    }

    public static function login(string $value, bool $encoded = false): string
    {
        if ($encoded) { $value = (string) @iconv_mime_decode($value, ICONV_MIME_DECODE_STRICT, 'UTF-8'); }
        if (!mb_check_encoding($value, 'UTF-8') || strlen($value) > 320) {
            throw new \DomainException('invalid_identity');
        }
        $value = mb_convert_case(trim($value), MB_CASE_FOLD, 'UTF-8');
        if ($value === '' || strlen($value) > 254 || preg_match('/[\s\x00-\x1f\x7f,;<>]/u', $value)) {
            throw new \DomainException('invalid_identity');
        }
        return $value;
    }

    private function principal(array $row): Principal
    {
        $servers = json_decode($row['servers'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($servers) || !array_is_list($servers) || array_diff($servers, ['aska','enshrouded'])) {
            throw new \RuntimeException('invalid_grant');
        }
        return new Principal($row['id'], $row['login'], $row['name'], Role::from($row['role']),
            (int) $row['revision'], $servers);
    }

    public function byLogin(string $login): Principal
    {
        $query = $this->db->prepare('SELECT * FROM grants WHERE login = ? AND enabled = 1');
        $query->execute([self::login($login, true)]);
        $row = $query->fetch();
        if ($row === false) { throw new \DomainException('access_denied'); }
        return $this->principal($row);
    }

    public function byId(string $id, int $revision): Principal
    {
        if ($id === 'local-owner' && $revision === 0) { return Principal::owner(); }
        $query = $this->db->prepare('SELECT * FROM grants WHERE id = ? AND enabled = 1 AND revision = ?');
        $query->execute([$id, $revision]);
        $row = $query->fetch();
        if ($row === false) { throw new \DomainException('access_denied'); }
        return $this->principal($row);
    }

    public function authorize(Principal $actor, string $permission, ?string $server = null): void
    {
        $current = $this->byId($actor->id, $actor->revision);
        if ($current != $actor || !in_array($permission, ['read','start','stop','access.manage'], true)
            || ($permission === 'access.manage' && !$current->role->canManageAccess())
            || ($server !== null && !in_array($server, $current->servers, true))) {
            throw new \DomainException('access_denied');
        }
    }

    /** Serialize grant changes with permission check and acceptance/beginning of actions. */
    public function guarded(Principal $actor, string $permission, ?string $server, callable $action): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->authorize($actor, $permission, $server);
            $result = $action();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function view(Principal $actor): array
    {
        $this->authorize($actor, 'access.manage');
        $rows = $this->db->query('SELECT * FROM grants ORDER BY name, login')->fetchAll();
        foreach ($rows as &$row) {
            $row['enabled'] = (bool) $row['enabled'];
            $row['revision'] = (int) $row['revision'];
            $row['servers'] = json_decode($row['servers'], true, flags: JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    public function createGrant(Principal $actor, string $login, Role $role): void
    {
        $login = self::login($login);
        $this->guarded($actor, 'access.manage', null, function () use ($login, $role): void {
            $query = $this->db->prepare('SELECT id FROM grants WHERE login = ?');
            $query->execute([$login]);
            if ($query->fetch() !== false) { throw new \DomainException('identity_exists'); }
            $query = $this->db->prepare('INSERT INTO grants VALUES (?,?,?,?,1,1,?)');
            $query->execute([bin2hex(random_bytes(16)), $login, mb_convert_case(explode('@', $login)[0], MB_CASE_TITLE, 'UTF-8'),
                $role->value, '["aska","enshrouded"]']);
        });
    }

    public function changeGrant(Principal $actor, string $id, int $revision, ?bool $enabled, ?Role $role): void
    {
        $this->guarded($actor, 'access.manage', null, function () use ($actor, $id, $revision, $enabled, $role): void {
            if ($id === $actor->id || $id === 'local-owner') { throw new \DomainException('own_access_protected'); }
            $query = $this->db->prepare('UPDATE grants SET enabled = COALESCE(?,enabled),
                role = COALESCE(?,role), revision = revision+1 WHERE id = ? AND revision = ?');
            $query->execute([$enabled === null ? null : (int) $enabled, $role?->value, $id, $revision]);
            if ($query->rowCount() !== 1) { throw new \DomainException('grant_changed'); }
        });
    }

    public function token(Principal $actor, string $origin): string
    {
        $this->authorize($actor, 'read');
        return hash_hmac('sha256', json_encode([$origin, $actor], JSON_THROW_ON_ERROR), $this->key);
    }

    public function checkWrite(Principal $actor, string $origin, array $headers, string $token): void
    {
        $this->authorize($actor, 'read');
        if (($headers['origin'] ?? '') !== $origin || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !hash_equals($this->token($actor, $origin), $token)) {
            throw new \DomainException('invalid_csrf');
        }
    }
}

final class ListenerIdentity
{
    public static function resolve(AccessStore $access, array $server, array $headers,
        string $localPort, ?string $familyHost): array
    {
        if (($server['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { throw new \DomainException('access_denied'); }
        // FrankenPHP omits Host from getallheaders(); its parsed Host lives in HTTP_HOST.
        $host = $server['HTTP_HOST'] ?? '';
        if (isset($headers['host']) && $headers['host'] !== $host) { throw new \DomainException('access_denied'); }
        // Only Caddy's php_server environment sets INDY_LISTENER; HTTP_ values are never authoritative.
        if (($server['INDY_LISTENER'] ?? '') === 'local' && $host === '127.0.0.1:'.$localPort) {
            return [Principal::owner(), 'http://'.$host];
        }
        if (($server['INDY_LISTENER'] ?? '') !== 'family' || !$familyHost
            || !in_array($host, [$familyHost, $familyHost.':443'], true)
            || !isset($headers['tailscale-user-login'])) {
            throw new \DomainException('access_denied');
        }
        return [$access->byLogin($headers['tailscale-user-login']), 'https://'.$host];
    }

    public static function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            if (isset($result[$name]) || !is_string($value)) { throw new \DomainException('access_denied'); }
            $result[$name] = $value;
        }
        return $result;
    }
}

final class AuthorizedOperations
{
    public function __construct(private AccessStore $access, private FixtureStore $store) {}

    public function reserve(Principal $actor, string $server, string $action): array
    {
        if (!in_array($action, ['start','stop'], true)) { throw new \DomainException('access_denied'); }
        return $this->access->guarded($actor, $action, $server,
            fn () => $this->store->reserve($server, $action, $actor->id, $actor->role, $actor->revision));
    }

    public function begin(string $id): void
    {
        $operation = $this->store->operation($id);
        if ($operation['state'] !== 'pending') { throw new \RuntimeException('invalid_transition'); }
        try {
            $actor = $this->access->byId($operation['actor'], (int) $operation['actor_revision']);
            $this->access->guarded($actor, $operation['action'], $operation['server'],
                fn () => $this->store->transition($id, 'running'));
        } catch (\DomainException $error) {
            $this->store->transition($id, 'cancelled');
            throw $error;
        }
    }
}
