<?php
declare(strict_types=1);
namespace App\WhatsApp;

use App\Instance\NativePaths;
use App\Storage\PrivateFiles;

/** Explicit copy package; sessions and credentials never enter it or get re-linked. */
final class WhatsAppSnapshot
{
    public function __construct(private readonly NativePaths $paths) {}
    public function copy(string $source, string $target): array
    {
        $source = $this->paths->local($source); $target = $this->paths->local($target);
        if (!is_dir($source) || is_link($source) || file_exists($target) || is_link($target) || $this->paths->contains($source,$target)) { throw new \DomainException('Bestehenden Quellordner und neuen getrennten Kopierordner verwenden.'); }
        $settings = $source.'/settings.json'; $database = $source.'/deliveries.sqlite3';
        if (!is_file($settings) || !is_file($database) || is_link($settings) || is_link($database)) { throw new \DomainException('Vollständiger privater WhatsApp-Bestand erforderlich.'); }
        $bytes = PrivateFiles::read($settings,65536); $configuration = WhatsApp::validate(PrivateFiles::object($bytes));
        PrivateFiles::directory($target); $file = $target.'/deliveries.sqlite3';
        $db = null;
        try {
            $original = null; $copied = null;
            try {
                $original = new \SQLite3($database,SQLITE3_OPEN_READONLY); $copied = new \SQLite3($file,SQLITE3_OPEN_READWRITE|SQLITE3_OPEN_CREATE);
                if (!$original->backup($copied)) { throw new \DomainException('Versandsnapshot nicht anlegbar.'); }
            } finally { $original?->close(); $copied?->close(); }
            $db = DeliveryStore::open($file); DeliveryStore::validate($db);
            $counts = $db->query('SELECT state,COUNT(*) AS count FROM deliveries GROUP BY state ORDER BY state')->fetchAll();
            $count = (int) $db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn(); $db = null;
            if (PrivateFiles::read($settings,65536) !== $bytes) { throw new \DomainException('WhatsApp-Einstellungen während der Kopie geändert.'); }
            chmod($file,0600); PrivateFiles::write($target.'/settings.json',$bytes);
            $manifest = (object) ['schema'=>1,'revision'=>$configuration->revision,'claims'=>$count,'states'=>$counts,
                'settings_sha256'=>hash('sha256',$bytes),'database_sha256'=>hash_file('sha256',$file)];
            PrivateFiles::write($target.'/manifest.json',PrivateFiles::json($manifest));
            return ['revision'=>$configuration->revision,'claims'=>$count,'sha256'=>$manifest->database_sha256];
        } catch (\Throwable $error) {
            // Exception arguments can retain PDO even after its local variable is unset.
            // Release that trace before cleanup so Windows can remove the rejected copy.
            unset($error); $db = null;
            foreach (['manifest.json','settings.json','deliveries.sqlite3','deliveries.sqlite3-wal','deliveries.sqlite3-shm'] as $name) { if (is_file($target.'/'.$name)) { unlink($target.'/'.$name); } }
            @rmdir($target); throw new \DomainException('WhatsApp-Kopie nicht übernommen. Quelle und Struktur prüfen.');
        }
    }
}
