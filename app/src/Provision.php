<?php
declare(strict_types=1);

namespace IndyNext;

final class Provision
{
    /** Read the stopped legacy app's current grants, never its world/history databases. */
    public static function grants(string $path): array
    {
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600) {
            throw new \RuntimeException('private_legacy_grants_required');
        }
        $db = new \PDO('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC, \Pdo\Sqlite::ATTR_OPEN_FLAGS=>\Pdo\Sqlite::OPEN_READONLY]);
        $db->beginTransaction();
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() !== 1) { throw new \RuntimeException('unsupported_legacy_access'); }
        $result = [];
        foreach ($db->query('SELECT id,login,name,role,enabled,revision,servers FROM users ORDER BY id')->fetchAll() as $row) {
            if (!preg_match('/^user-([a-f0-9]{32})$/D', $row['id'], $id)
                || !in_array($row['role'], ['owner','family','viewer'], true)
                || !in_array($row['enabled'], [0,1], true) || $row['revision'] < 1 || $row['revision'] > 999999999
                || !mb_check_encoding($row['name'], 'UTF-8') || mb_strlen($row['name']) < 1 || mb_strlen($row['name']) > 80
                || preg_match('/[\x00-\x1f\x7f]/u', $row['name'])) { throw new \RuntimeException('invalid_legacy_grant'); }
            $servers = $row['servers'] === null ? ['aska','enshrouded'] : json_decode($row['servers'], true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($servers) || !array_is_list($servers) || array_diff($servers, ['aska','enshrouded'])
                || count(array_unique($servers)) !== count($servers)) { throw new \RuntimeException('invalid_legacy_servers'); }
            sort($servers);
            $result[] = ['id'=>$id[1], 'login'=>AccessStore::login($row['login']), 'name'=>$row['name'],
                'role'=>$row['role'] === 'owner' ? 'administrator' : 'family',
                'enabled'=>$row['role'] === 'viewer' ? 0 : $row['enabled'], 'revision'=>(int) $row['revision'],
                'servers'=>json_encode($servers, JSON_THROW_ON_ERROR)];
        }
        $db->commit();
        return $result;
    }

    public static function fingerprint(string $path): string
    {
        return hash('sha256', json_encode(self::grants($path), JSON_THROW_ON_ERROR));
    }

    public static function create(array $configuration): void
    {
        $rows = self::grants($configuration['legacy_access']);
        $directory = $configuration['directory'];
        if (!is_string($directory) || !str_starts_with($directory, '/') || file_exists($directory) || is_link($directory)) {
            throw new \RuntimeException('new_state_directory_required');
        }
        $revision = $configuration['revision'];
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{40}$/D', $revision)) { throw new \RuntimeException('invalid_revision'); }
        FixtureStore::create($directory);
        AccessStore::create($directory);
        $db = new \PDO('sqlite:'.$directory.'/access.sqlite', options: [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        $insert = $db->prepare('INSERT INTO grants (id,login,name,role,enabled,revision,servers) VALUES (?,?,?,?,?,?,?)');
        foreach ($rows as $row) { $insert->execute(array_values($row)); }
        $db->commit();
        foreach (['runtime.json'=>json_encode($configuration['runtime'], JSON_THROW_ON_ERROR),
            'service.json'=>json_encode($configuration['service'], JSON_THROW_ON_ERROR), 'active-release'=>$revision."\n",
            'maintenance'=>"Prepared; not activated.\n",
            'grant-import.json'=>json_encode(['fingerprint'=>hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'count'=>count($rows)], JSON_THROW_ON_ERROR)] as $name=>$value) {
            if (file_put_contents($directory.'/'.$name, $value) !== strlen($value) || !chmod($directory.'/'.$name, 0600)) {
                throw new \RuntimeException('provision_write_failed');
            }
        }
        (new AccessStore($directory))->view(Principal::owner());
        new ServerRuntime($directory);
    }
}
