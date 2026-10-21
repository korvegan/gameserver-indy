<?php
declare(strict_types=1);
namespace App\Backup;

use App\Storage\PrivateFiles;

/** Reads the existing Python schema-1 ZIP format without general-purpose extraction. */
final class BackupArchive
{
    public function __construct(private readonly BackupFiles $files) {}
    public function create(string $path, array $inventory, \stdClass $manifest): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) { throw new \DomainException('Sicherungsarchiv nicht exklusiv anlegbar.'); }
        chmod($path, 0600); fclose($handle);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \DomainException('Sicherungsarchiv nicht anlegbar.'); }
        try {
            $manifest->files = [];
            foreach ($inventory['entries'] as $name => $source) {
                BackupFiles::member($name);
                $signature = $inventory['signature'][$name];
                if (!$zip->addFile($this->files->plain($source), $name)
                    || !$zip->setCompressionName($name, \ZipArchive::CM_DEFLATE, 1)
                    || !$zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, 0100600 << 16)) { throw new \DomainException('Datei nicht zum Archiv hinzugefügt.'); }
                $manifest->files[] = (object) ['name' => $name, 'size' => $signature[2], 'sha256' => $signature[5]];
            }
            $bytes = PrivateFiles::json($manifest);
            if (strlen($bytes) > BackupFiles::MANIFEST_LIMIT || !$zip->addFromString('manifest.json', $bytes)) { throw new \DomainException('Sicherungsmanifest zu groß oder nicht schreibbar.'); }
            if (!$zip->close()) { throw new \DomainException('Archiv nicht vollständig geschrieben.'); }
            $zip = null;
            chmod($path, 0600); $handle = fopen($path, 'r+b');
            if ($handle === false) { throw new \DomainException('Archiv nicht vollständig lesbar.'); }
            try { if (!fsync($handle)) { throw new \DomainException('Archiv nicht auf Datenträger bestätigt.'); } } finally { fclose($handle); }
        } finally { if ($zip !== null) { $zip->close(); } }
    }
    private function open(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->files->plain($path), \ZipArchive::RDONLY) !== true) { throw new \DomainException('Sicherungsarchiv beschädigt oder nicht lesbar.'); }
        return $zip;
    }
    public function inspect(string $path, \stdClass $expected): void
    {
        $zip = $this->open($path);
        try {
            if ($zip->numFiles > BackupFiles::MAX_FILES + 1) { throw new \DomainException('Zu viele Archivdateien.'); }
            $names = []; $sizes = []; $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $info = $zip->statIndex($index);
                if (!is_array($info) || !is_int($info['size'] ?? null) || $info['size'] < 0 || ($info['encryption_method'] ?? 0) !== 0) { throw new \DomainException('Ungültige Archivdatei.'); }
                $name = BackupFiles::member($info['name']); $folded = mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
                $system = $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)
                    || ($system === \ZipArchive::OPSYS_UNIX && !in_array(($attributes >> 16) & 0170000, [0, 0100000], true))
                    || ($attributes & 0x10) !== 0 || isset($names[$folded])) { throw new \DomainException('Mehrdeutige Archivnamen oder unzulässige Dateitypen.'); }
                $names[$folded] = true; $sizes[$name] = $info['size']; $total += $info['size'];
                if ($total > BackupFiles::MAX_BYTES + BackupFiles::MANIFEST_LIMIT) { throw new \DomainException('Sicherungsarchiv überschreitet das Größenlimit.'); }
            }
            if (!isset($sizes['manifest.json']) || $sizes['manifest.json'] > BackupFiles::MANIFEST_LIMIT) { throw new \DomainException('Sicherungsmanifest fehlt oder ist zu groß.'); }
            $bytes = $zip->getFromName('manifest.json');
            if (!is_string($bytes)) { throw new \DomainException('Sicherungsmanifest nicht lesbar.'); }
            $manifest = PrivateFiles::object($bytes);
            if ($manifest != $expected || ($manifest->schema ?? null) !== 1 || !is_array($manifest->files ?? null)
                || !is_array($manifest->directories ?? null) || count($manifest->files) + count($manifest->directories) > BackupFiles::MAX_FILES
                || !in_array('world', $manifest->directories, true)) { throw new \DomainException('Archiv und Sicherungsmanifest passen nicht zusammen.'); }
            $expectedNames = ['manifest.json']; $directories = [];
            foreach ($manifest->directories as $directory) {
                BackupFiles::member($directory);
                if ($directory !== 'world' && !str_starts_with($directory, 'world/')) { throw new \DomainException('Ungültiger Weltordner im Manifest.'); }
                $key = mb_convert_case($directory, MB_CASE_FOLD, 'UTF-8');
                if (isset($directories[$key]) || isset($names[$key])) { throw new \DomainException('Mehrdeutiger Manifestordner.'); } $directories[$key] = $directory;
            }
            $sortedDirectories = $manifest->directories; sort($sortedDirectories);
            if ($sortedDirectories !== $manifest->directories) { throw new \DomainException('Manifestordner sind nicht eindeutig geordnet.'); }
            foreach ($manifest->directories as $directory) {
                if ($directory !== 'world' && !in_array(dirname($directory), $manifest->directories, true)) { throw new \DomainException('Manifestordner besitzt keinen passenden Elternordner.'); }
            }
            foreach ($manifest->files as $item) {
                if (!$item instanceof \stdClass || !is_string($item->name ?? null) || !is_int($item->size ?? null) || $item->size < 0
                    || !is_string($item->sha256 ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $item->sha256)) { throw new \DomainException('Ungültiger Dateieintrag im Manifest.'); }
                $name = BackupFiles::member($item->name);
                if (!(str_starts_with($name, 'world/') || in_array($name, ['config/server', 'config/preset'], true))
                    || ($sizes[$name] ?? null) !== $item->size || (str_starts_with($name, 'config/') && $item->size > BackupFiles::CONFIG_LIMIT)) { throw new \DomainException('Unbekannte oder unvollständige Sicherungsdatei.'); }
                if (str_starts_with($name, 'world/') && !in_array(dirname($name), $manifest->directories, true)) { throw new \DomainException('Weltdatei besitzt keinen passenden Manifestordner.'); }
                $this->stream($zip, $name, $item->size, $item->sha256); $expectedNames[] = $name;
            }
            sort($expectedNames); $actual = array_keys($sizes); sort($actual);
            if ($actual !== $expectedNames || !in_array('config/server', $expectedNames, true)
                || !array_filter($expectedNames, fn ($name) => str_starts_with($name, 'world/')) && ($manifest->empty_world ?? false)!==true) { throw new \DomainException('Das Sicherungsarchiv ist unvollständig.'); }
        } finally { $zip->close(); }
    }
    /** Stream at most the declared size, checking hashes before any staged file is used. */
    private function stream(\ZipArchive $zip, string $name, int $size, string $sha256, mixed $output = null): void
    {
        $input = $zip->getStream($name);
        if ($input === false) { throw new \DomainException('Archivdatei nicht lesbar.'); }
        $count = 0; $hash = hash_init('sha256');
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false || ($chunk === '' && !feof($input))) { throw new \DomainException('Archivdatei nicht vollständig lesbar.'); }
                $count += strlen($chunk);
                if ($count > $size || $count > BackupFiles::MAX_BYTES) { throw new \DomainException('Archivdatei überschreitet ihr Größenlimit.'); }
                hash_update($hash, $chunk);
                if ($output !== null && fwrite($output, $chunk) !== strlen($chunk)) { throw new \DomainException('Datei nicht vollständig geschrieben.'); }
            }
            if ($count !== $size || !hash_equals($sha256, hash_final($hash))) { throw new \DomainException('Eine Prüfsumme der Weltsicherung stimmt nicht.'); }
        } finally { fclose($input); }
    }
    public function stage(string $path, \stdClass $manifest, string $stage): void
    {
        $zip = $this->open($path);
        try {
            foreach ($manifest->directories as $name) { PrivateFiles::directory($stage.'/'.BackupFiles::member($name)); }
            foreach ($manifest->files as $item) {
                $path = $stage.'/'.BackupFiles::member($item->name); PrivateFiles::directory(dirname($path));
                $handle = fopen($path, 'xb');
                if ($handle === false) { throw new \DomainException('Wiederherstellung nicht vorbereitbar.'); }
                try {
                    chmod($path, 0600); $this->stream($zip, $item->name, $item->size, $item->sha256, $handle);
                    if (!fflush($handle) || !fsync($handle)) { throw new \DomainException('Vorbereitete Datei nicht auf Datenträger bestätigt.'); }
                } finally { fclose($handle); }
            }
        } finally { $zip->close(); }
    }
}
