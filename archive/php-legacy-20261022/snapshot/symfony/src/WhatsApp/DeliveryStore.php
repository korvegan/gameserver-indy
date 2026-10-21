<?php
declare(strict_types=1);
namespace App\WhatsApp;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Original unversioned database, with explicit structure and state validation. */
final class DeliveryStore
{
    public function __construct(private readonly SliceRuntime $runtime) {}
    public function path(): string { return $this->runtime->directory.'/data/whatsapp/deliveries.sqlite3'; }
    public static function validate(\PDO $db): void
    {
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() !== 0) { throw new \DomainException('Unbekannte WhatsApp-Versandversion.'); }
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type IN ('table','view','trigger') AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $columns = $db->query('PRAGMA table_info(deliveries)')->fetchAll(\PDO::FETCH_ASSOC);
        if ($tables !== ['deliveries'] || array_column($columns, 'name') !== ['server_id','process_key','group_id','state','updated_at']
            || array_map(intval(...), array_column($columns, 'pk')) !== [1,2,3,0,0]
            || array_unique(array_column($columns, 'type')) !== ['TEXT']
            || array_map(intval(...), array_column($columns,'notnull')) !== [1,1,1,1,1]
            || $db->query("SELECT COUNT(*) FROM deliveries WHERE state NOT IN ('sent','sending','uncertain')")->fetchColumn() != 0) { throw new \DomainException('WhatsApp-Versandstruktur ist nicht kompatibel.'); }
    }
    public static function open(string $path): \PDO
    {
        $db = new \Pdo\Sqlite('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA busy_timeout=5000'); return $db;
    }
    public function initialize(): void
    {
        $this->runtime->assertInitialized(); PrivateFiles::directory(dirname($this->path()));
        if (is_link($this->path())) { throw new \DomainException('Verknüpfte Versanddatenbank abgewiesen.'); }
        $existing = is_file($this->path()); $db = self::open($this->path());
        if (!$existing) { $db->exec('CREATE TABLE deliveries (server_id TEXT NOT NULL, process_key TEXT NOT NULL, group_id TEXT NOT NULL, state TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(server_id,process_key,group_id))'); }
        self::validate($db); chmod($this->path(),0600);
    }
    private function connection(): \PDO
    {
        $this->runtime->assertInitialized(); if (!is_file($this->path()) || is_link($this->path())) { throw new \DomainException('WhatsApp-Versandspeicher ist nicht initialisiert.'); }
        $db = self::open($this->path()); self::validate($db); return $db;
    }
    public function state(string $id, string $key, string $group): ?string
    {
        $db = $this->connection(); $query = $db->prepare('SELECT state FROM deliveries WHERE server_id=? AND process_key=? AND group_id=?'); $query->execute([$id,$key,$group]);
        $state = $query->fetchColumn(); return $state === false ? null : ($state === 'sending' ? 'uncertain' : $state);
    }
    public function claim(string $id, string $key, string $group): bool
    {
        $db = $this->connection(); $db->exec('BEGIN IMMEDIATE');
        try {
            $query = $db->prepare('SELECT state FROM deliveries WHERE server_id=? AND process_key=? AND group_id=?'); $query->execute([$id,$key,$group]); $old = $query->fetchColumn();
            if ($old === 'sent') { $db->exec('COMMIT'); return false; }
            if ($old !== false) { throw new \DomainException('Der vorherige Versand ist unklar. Bitte in WhatsApp prüfen; keine automatische Wiederholung.'); }
            $db->prepare('INSERT INTO deliveries VALUES (?,?,?,?,?)')->execute([$id,$key,$group,'sending',gmdate('Y-m-d\TH:i:s\Z')]); $db->exec('COMMIT'); return true;
        } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
    }
    public function finish(string $id, string $key, string $group, bool $sent): void
    {
        $this->connection()->prepare('UPDATE deliveries SET state=?,updated_at=? WHERE server_id=? AND process_key=? AND group_id=?')
            ->execute([$sent ? 'sent' : 'uncertain',gmdate('Y-m-d\TH:i:s\Z'),$id,$key,$group]);
    }
    /** Only the single supervisor calls this after acquiring its lifetime lease. */
    public function reconcile(): void { $this->connection()->exec("UPDATE deliveries SET state='uncertain' WHERE state='sending'"); }
}
