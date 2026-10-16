<?php
declare(strict_types=1);
namespace App\Community;

use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Existing platform schema, separate from current Messenger jobs and access grants. */
final class PlatformDatabase
{
    public const SCHEMA = [
        'CREATE TABLE jobs (id TEXT PRIMARY KEY,server_id TEXT NOT NULL,operation TEXT NOT NULL,actor_id TEXT NOT NULL,state TEXT NOT NULL,message TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,result TEXT)',
        'CREATE INDEX jobs_by_server ON jobs(server_id,created_at)',
        'CREATE TABLE community_events (id TEXT NOT NULL,revision INTEGER NOT NULL,game_id TEXT NOT NULL,instance_id TEXT,payload TEXT NOT NULL,recorded_at TEXT NOT NULL,PRIMARY KEY(id,revision))',
        'CREATE TABLE scenes (id TEXT PRIMARY KEY,event_id TEXT NOT NULL UNIQUE,position INTEGER NOT NULL UNIQUE,template TEXT NOT NULL)',
        'CREATE TABLE deliveries (event_id TEXT NOT NULL,revision INTEGER NOT NULL,target TEXT NOT NULL,state TEXT NOT NULL,remote_id TEXT,PRIMARY KEY(event_id,revision,target),FOREIGN KEY(event_id,revision) REFERENCES community_events(id,revision))',
    ];
    public const MEDIA = 'CREATE TABLE community_media (id TEXT PRIMARY KEY,content BLOB NOT NULL,width INTEGER NOT NULL,height INTEGER NOT NULL)';
    public function __construct(private readonly SliceRuntime $runtime) {}
    public function path(): string { return $this->runtime->directory.'/data/platform.sqlite3'; }
    public function initialize(): void
    {
        $this->runtime->assertInitialized(); PrivateFiles::directory(dirname($this->path()));
        if (is_link($this->path())) { throw new \DomainException('Verknüpfte Plattformdatenbank abgewiesen.'); }
        $db = self::open($this->path()); $db->exec('BEGIN IMMEDIATE');
        try {
            $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
            if ($version === 0) {
                if ($db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn() != 0) { throw new \DomainException('Unversionierte Datenbank wird nicht überschrieben.'); }
                foreach ([...self::SCHEMA, self::MEDIA, 'PRAGMA user_version=2'] as $statement) { $db->exec($statement); }
            } elseif ($version !== 2) { throw new \DomainException('Plattformversion nur im ausdrücklichen Kopierpaket migrieren.'); }
            $db->exec('COMMIT'); chmod($this->path(), 0600);
        } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
    }
    public static function open(string $path): \PDO
    {
        $db = new \Pdo\Sqlite('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA busy_timeout=5000'); $db->exec('PRAGMA foreign_keys=ON');
        $db->createFunction('chronicle_time', ChronicleValues::time(...), 2, \Pdo\Sqlite::DETERMINISTIC);
        return $db;
    }
    public function connect(): \PDO
    {
        $this->runtime->assertInitialized();
        if (!is_file($this->path()) || is_link($this->path())) { throw new \DomainException('Plattformdatenbank zuerst ausdrücklich initialisieren oder aus einer geprüften Kopie übernehmen.'); }
        $db = self::open($this->path());
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() !== 2) { throw new \DomainException('Plattformdatenbankversion nicht unterstützt.'); }
        return $db;
    }
    public function read(callable $query): mixed
    {
        $db = $this->connect(); $db->beginTransaction();
        try { $result = $query($db); $db->commit(); return $result; } catch (\Throwable $error) { $db->rollBack(); throw $error; }
    }
    public function write(callable $change): mixed
    {
        $db = $this->connect(); $db->exec('BEGIN IMMEDIATE');
        try { $result = $change($db); $db->exec('COMMIT'); return $result; } catch (\Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
    }
    /** Once per supervisor epoch; do not reset other workers' live deliveries per HTTP request. */
    public function reconcileDeliveries(): void { $this->write(fn (\PDO $db) => $db->exec("UPDATE deliveries SET state='uncertain' WHERE state='sending'")); }
}
