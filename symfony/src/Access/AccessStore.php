<?php
declare(strict_types=1);

namespace App\Access;

use App\Runtime\SliceRuntime;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Compatible grant/audit schema, deliberately outside chronicle storage. */
final class AccessStore
{
    public function __construct(private readonly SliceRuntime $runtime) {}
    public function databasePath(): string { return $this->runtime->directory.'/access.sqlite3'; }
    public function settingsPath(): string { return $this->runtime->directory.'/access-settings.json'; }
    public function initialize(): void
    {
        $db = $this->connect(); $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if (!in_array($version, [0, 1], true)) { throw new \RuntimeException('Unbekannte Zugangsdatenbankversion.'); }
        if ($version === 0) {
            $db->beginTransaction();
            $db->exec('CREATE TABLE users (id TEXT PRIMARY KEY,login TEXT UNIQUE NOT NULL,name TEXT NOT NULL,
                role TEXT NOT NULL,servers TEXT,enabled INTEGER NOT NULL,revision INTEGER NOT NULL,
                created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
            $db->exec('CREATE TABLE audit (id INTEGER PRIMARY KEY,at TEXT NOT NULL,actor TEXT NOT NULL,
                operation TEXT NOT NULL,resource TEXT NOT NULL,outcome TEXT NOT NULL)');
            $db->exec('PRAGMA user_version=1'); $db->commit();
        }
        chmod($this->databasePath(), 0600);
    }
    private function connect(): \PDO
    {
        $this->runtime->assertInitialized();
        $db = new \PDO('sqlite:'.$this->databasePath(), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA busy_timeout=5000'); return $db;
    }
    public static function configuration(array $value): array
    {
        if (array_diff(array_keys($value), ['enabled', 'hostname', 'port'])) { throw new \InvalidArgumentException('Unbekanntes Zugangsfeld.'); }
        $enabled = array_key_exists('enabled', $value) ? $value['enabled'] : false;
        $hostname = array_key_exists('hostname', $value) ? $value['hostname'] : '';
        $port = array_key_exists('port', $value) ? $value['port'] : 8767;
        if (!is_bool($enabled) || !is_int($port) || $port < 1024 || $port > 65535 || !is_string($hostname)
            || ($hostname !== '' && (strlen($hostname) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.){2,}ts\.net$/D', $hostname)))
            || ($enabled && $hostname === '')) { throw new \InvalidArgumentException('Ungültiger privater HTTPS-Name oder Port.'); }
        return ['enabled' => $enabled, 'hostname' => $hostname, 'port' => $port];
    }
    public function settings(): array
    {
        return self::configuration(is_file($this->settingsPath())
            ? json_decode(file_get_contents($this->settingsPath()), true, flags: JSON_THROW_ON_ERROR) : []);
    }
    public static function login(string $login, bool $encoded = false): string
    {
        if (!mb_check_encoding($login, 'UTF-8') || mb_strlen($login) > 320) { throw new \InvalidArgumentException('Ungültige persönliche Tailscale-Identität.'); }
        if ($encoded) { $login = @iconv_mime_decode($login, ICONV_MIME_DECODE_STRICT, 'UTF-8') ?: ''; }
        $login = mb_convert_case(preg_replace('/^\s+|\s+$/u', '', $login), MB_CASE_FOLD, 'UTF-8');
        if ($login === '' || mb_strlen($login) > 254 || preg_match('/[\s\x00-\x1f\x7f]/u', $login)) {
            throw new \InvalidArgumentException('Ungültige persönliche Tailscale-Identität.');
        }
        return $login;
    }
    public function principal(string $login): Principal
    {
        $query = $this->connect()->prepare('SELECT * FROM users WHERE login=? AND enabled=1');
        $query->execute([self::login($login, true)]); $row = $query->fetch();
        if (!$row) { throw new AccessDeniedException('Dieser persönliche Zugang ist nicht freigegeben.'); }
        return new Principal($row['id'], $row['role'], $row['servers'] === null ? null
            : json_decode($row['servers'], true, flags: JSON_THROW_ON_ERROR), (int) $row['revision']);
    }
    public function validate(Principal $principal): void
    {
        if ($principal->revision === 0 && $principal->id === 'local-owner') { return; }
        if (!$this->settings()['enabled']) { throw new AccessDeniedException('Der private Familienzugang ist deaktiviert.'); }
        $query = $this->connect()->prepare('SELECT enabled,revision FROM users WHERE id=?');
        $query->execute([$principal->id]); $row = $query->fetch();
        if (!$row || !(bool) $row['enabled'] || (int) $row['revision'] !== $principal->revision) {
            throw new AccessDeniedException('Die Freigabe wurde geändert oder entzogen. Bitte neu laden.');
        }
    }
    public function view(): array
    {
        $db = $this->connect();
        return ['settings' => $this->settings(), 'users' => array_map(self::publicRow(...),
            $db->query('SELECT * FROM users ORDER BY name,id')->fetchAll()),
            'audit' => $db->query('SELECT * FROM audit ORDER BY id DESC LIMIT 100')->fetchAll()];
    }
    private static function publicRow(array $row): array
    {
        $row['server_ids'] = $row['servers'] === null ? null : json_decode($row['servers'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['servers']); $row['enabled'] = (bool) $row['enabled']; $row['revision'] = (int) $row['revision']; return $row;
    }
    public function saveUser(Principal $actor, array $body, array $knownServers): array
    {
        $this->requireManager($actor);
        if (array_diff(array_keys($body), ['id', 'revision', 'login', 'name', 'role', 'server_ids', 'enabled'])
            || !is_string($body['login'] ?? null) || !is_string($body['name'] ?? null)) { throw new \InvalidArgumentException('Ungültige Freigabefelder.'); }
        $login = self::login($body['login']); $name = trim($body['name']); $role = $body['role'] ?? null;
        $ids = $body['server_ids'] ?? null; $enabled = array_key_exists('enabled', $body) ? $body['enabled'] : true;
        if (mb_strlen($name) < 1 || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $name)
            || !in_array($role, ['owner', 'family', 'viewer'], true) || !is_bool($enabled)
            || ($role === 'owner' ? $ids !== null : (!is_array($ids) || !array_is_list($ids)
                || array_filter($ids, fn ($id) => !is_string($id) || !in_array($id, $knownServers, true))
                || count($ids) !== count(array_unique($ids))))) { throw new \InvalidArgumentException('Name, Rolle oder Serverfreigabe ungültig.'); }
        $db = $this->connect(); $db->exec('BEGIN IMMEDIATE');
        try {
            $this->requireManager($actor);
            $id = $body['id'] ?? null;
            $query = $db->prepare('SELECT * FROM users WHERE id=?'); $query->execute([is_string($id) ? $id : '']); $previous = $query->fetch();
            if ($id !== null && (!$previous || !is_int($body['revision'] ?? null) || (int) $previous['revision'] !== $body['revision'])) {
                throw new \DomainException('Die Freigabe wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            if ($previous && $login !== $previous['login']) { throw new \DomainException('Für eine neue Identität einen neuen Zugang anlegen.'); }
            $query = $db->prepare('SELECT id FROM users WHERE login=?'); $query->execute([$login]); $conflict = $query->fetchColumn();
            if ($conflict && (!$previous || $conflict !== $id)) { throw new \DomainException('Diese Identität besitzt bereits eine Freigabe.'); }
            $id ??= 'user-'.bin2hex(random_bytes(16)); $now = gmdate('c'); if (is_array($ids)) { sort($ids); }
            $query = $db->prepare('INSERT OR REPLACE INTO users VALUES (?,?,?,?,?,?,?,?,?)');
            $query->execute([$id, $login, $name, $role, $ids === null ? null : json_encode($ids, JSON_THROW_ON_ERROR),
                (int) $enabled, (int) ($previous['revision'] ?? 0) + 1, $previous['created_at'] ?? $now, $now]);
            $this->auditRow($db, $actor->id, 'access.user', $id, $enabled ? 'enabled' : 'revoked'); $db->exec('COMMIT');
        } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
        return ['message' => 'Persönliche Freigabe gespeichert.', 'id' => $id];
    }
    public function configure(Principal $actor, array $body): array
    {
        $this->requireManager($actor);
        $value = self::configuration($body); $temporary = $this->settingsPath().'.'.bin2hex(random_bytes(8));
        $db = $this->connect(); $db->exec('BEGIN IMMEDIATE');
        try {
            $this->requireManager($actor);
            if (file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !chmod($temporary, 0600) || !rename($temporary, $this->settingsPath())) { throw new \RuntimeException('Zugangseinstellungen nicht gespeichert.'); }
            $this->auditRow($db, $actor->id, 'access.configure', '', 'saved'); $db->exec('COMMIT');
        } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
        finally { if (is_file($temporary)) { unlink($temporary); } }
        return ['message' => 'Zugangseinstellungen gespeichert.', 'settings' => $value];
    }
    private function requireManager(Principal $actor): void
    {
        $this->validate($actor);
        if (!$actor->allows('access.manage')) { throw new AccessDeniedException('Zugangsverwaltung nur für Besitzer.'); }
    }
    public function audit(string $actor, string $operation, string $resource, string $outcome): void
    {
        $db = $this->connect(); $db->beginTransaction(); $this->auditRow($db, $actor, $operation, $resource, $outcome); $db->commit();
    }
    private function auditRow(\PDO $db, string $actor, string $operation, string $resource, string $outcome): void
    {
        $query = $db->prepare('INSERT INTO audit(at,actor,operation,resource,outcome) VALUES (?,?,?,?,?)');
        $query->execute([gmdate('c'), $actor, $operation, $resource, $outcome]); $db->exec('DELETE FROM audit WHERE id<=(SELECT MAX(id)-10000 FROM audit)');
    }
}
