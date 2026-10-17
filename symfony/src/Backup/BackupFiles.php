<?php
declare(strict_types=1);
namespace App\Backup;

use App\Instance\NativePaths;
use App\Runtime\NativePowerShell;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Bounded local file IO. Never traverse links or silently skip a failed scan. */
final class BackupFiles
{
    public const MAX_FILES = 100000;
    public const MAX_BYTES = 17179869184;
    public const CONFIG_LIMIT = 16777216;
    public const MANIFEST_LIMIT = 33554432;
    public const RESERVE = 536870912;
    public function __construct(private readonly NativePaths $paths, private readonly NativePowerShell $powershell,
        private readonly SliceRuntime $runtime, private readonly string $projectDirectory) {}
    public static function member(mixed $name): string
    {
        if (!is_string($name) || $name === '' || mb_strlen($name) > 1000 || str_contains($name, '\\')) { throw new \DomainException('Ungültiger Archivname.'); }
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[ .]$/D', $part)
                || preg_match('/[\x00-\x1f<>:"|?*]/u', $part)
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?$/iD', $part)) { throw new \DomainException('Unsicherer Archivname.'); }
        }
        return $name;
    }
    public function plain(string $path): string
    {
        $path = $this->paths->local($path); $parents = [];
        for ($part = $path; ; $part = dirname($part)) { $parents[] = $part; if (dirname($part) === $part) { break; } }
        foreach (array_reverse($parents) as $part) {
            clearstatcache(true, $part);
            $info = @lstat($part);
            if (is_link($part) || ($info !== false && ($info['mode'] & 0170000) === 0120000)) { throw new \DomainException('Verknüpfungen und Junctions sind für Weltsicherungen nicht erlaubt.'); }
        }
        // Like the existing Python abspath, retain configured 8.3 names for rollback.
        // NativePaths handles canonical identities separately for overlap protection.
        return $this->bindingPath($path);
    }
    /** Match Python abspath for stored bindings, preserving a configured 8.3 alias. */
    public function bindingPath(string $path): string
    {
        $path = str_replace('\\', '/', $this->paths->local($path)); $prefix = '/'; $minimum = 0;
        if (preg_match('/^([a-z]:)\/(.*)$/isD', $path, $match)) { $prefix = $match[1].'/'; $path = $match[2]; }
        elseif (str_starts_with($path, '//')) { $prefix = '//'; $minimum = 2; }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { if (count($parts) > $minimum) { array_pop($parts); } }
            else { $parts[] = $part; }
        }
        $result = $prefix.implode('/', $parts);
        return PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $result) : $result;
    }
    /** Reparse attributes and hardlink counts are checked natively on Windows volumes too. */
    public function native(array $paths, ?string $tree = null): void
    {
        $native = array_values(array_filter($paths, fn ($path) => PHP_OS_FAMILY === 'Windows'
            || preg_match('~^(?:/mnt/[a-z]/|[a-z]:[/\\\\]|\\\\)~i', $path)));
        if ($native === []) { return; }
        $folder = $this->runtime->directory.'/native/requests'; PrivateFiles::directory($folder);
        $path = $folder.'/backup-'.bin2hex(random_bytes(16)).'.json';
        try {
            PrivateFiles::write($path, PrivateFiles::json((object) ['paths' => array_map($this->powershell->windowsPath(...), $native),
                'tree' => $tree !== null && in_array($tree, $native, true) ? $this->powershell->windowsPath($tree) : null]));
            $process = $this->powershell->run($this->projectDirectory.'/tools/check-backup-paths.ps1',
                ['-RequestPath', $this->powershell->windowsPath($path)], 120);
            if (!$process->isSuccessful() || trim($process->getOutput()) !== 'plain') { throw new \DomainException('Dateibestand ist nativ nicht vollständig als gewöhnliche Dateien bestätigt. Links, Junctions und Zugriffsrechte prüfen.'); }
        } finally { if (file_exists($path)) { unlink($path); } }
    }
    public function inventory(string $world, array $extras, bool $requireWorldFiles = true): array
    {
        $this->native([$world, ...array_values($extras)], $world);
        $world = $this->plain($world);
        if (!is_dir($world)) { throw new \DomainException('Der konfigurierte Weltordner existiert noch nicht.'); }
        $entries = $extras; $directories = ['world']; $pending = [[$world, 'world']];
        while ($pending !== []) {
            [$parent, $name] = array_pop($pending); $children = @scandir($parent);
            if ($children === false) { throw new \DomainException('Der Weltordner ist nicht vollständig lesbar. Keine Sicherung erstellt.'); }
            foreach ($children as $child) {
                if ($child === '.' || $child === '..') { continue; }
                $relative = self::member($name.'/'.$child); $path = $this->plain($parent.'/'.$child);
                $info = @lstat($path);
                if ($info === false) { throw new \DomainException('Der Weltordner ist nicht vollständig lesbar.'); }
                if (($info['mode'] & 0170000) === 0040000) { $directories[] = $relative; $pending[] = [$path, $relative]; }
                elseif (($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1) { $entries[$relative] = $path; }
                else { throw new \DomainException('Die Welt enthält eine Verknüpfung oder nicht unterstützte Datei.'); }
                if (count($entries) + count($directories) > self::MAX_FILES) { throw new \DomainException('Zu viele Dateien für eine Weltsicherung.'); }
            }
        }
        if ($requireWorldFiles && count($entries) === count($extras)) { throw new \DomainException('Der Weltordner enthält keine Spielstanddateien.'); }
        $names = []; $signature = []; $total = 0; ksort($entries); sort($directories);
        foreach ([...array_keys($entries), ...$directories] as $name) {
            self::member($name); $key = mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
            if (isset($names[$key])) { throw new \DomainException('Unter Windows mehrdeutige Dateinamen.'); } $names[$key] = true;
        }
        foreach ($entries as $name => $path) {
            $path = $this->plain($path); clearstatcache(true, $path); $info = @lstat($path);
            if ($info === false || ($info['mode'] & 0170000) !== 0100000 || $info['nlink'] !== 1 || $info['size'] < 0) { throw new \DomainException('Konfigurationen und Weltdateien müssen gewöhnliche Dateien sein.'); }
            if (str_starts_with($name, 'config/') && $info['size'] > self::CONFIG_LIMIT) { throw new \DomainException('Eine Konfigurationsdatei überschreitet 16 MiB.'); }
            $total += $info['size']; if ($total > self::MAX_BYTES) { throw new \DomainException('Die Welt überschreitet 16 GiB.'); }
            // Hashes also detect same-second equal-size writes on filesystems with coarse PHP stat times.
            $signature[$name] = [$info['dev'], $info['ino'], $info['size'], $info['mtime'], $info['ctime'], $this->digest($path)];
        }
        return ['entries' => $entries, 'directories' => $directories, 'signature' => $signature, 'size' => $total];
    }
    public function digest(string $path): string
    {
        $value = @hash_file('sha256', $this->plain($path));
        return $value === false ? throw new \DomainException('Datei nicht vollständig lesbar.') : $value;
    }
    public function space(string $directory, int $required): void
    {
        $available = @disk_free_space($this->plain($directory));
        if ($available === false || $available < $required + self::RESERVE) { throw new \DomainException('Nicht genug freier Speicher. Bestehende Sicherungen bleiben erhalten.'); }
    }
    public function removeTree(string $path): void
    {
        $path = $this->plain($path);
        if (!is_dir($path)) { return; }
        $children = @scandir($path); if ($children === false) { throw new \DomainException('Temporärer Ordner nicht lesbar.'); }
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') { continue; }
            $target = $this->plain($path.'/'.$child);
            if (is_dir($target)) { $this->removeTree($target); }
            elseif (!unlink($target)) { throw new \DomainException('Temporäre Datei nicht entfernbar.'); }
        }
        if (!rmdir($path)) { throw new \DomainException('Temporärer Ordner nicht entfernbar.'); }
    }
}
