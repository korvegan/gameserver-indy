<?php
declare(strict_types=1);
namespace App\Instance;

final class NativePaths
{
    public function absolute(mixed $path): string
    {
        if (!is_string($path) || trim($path) === '' || strpbrk($path, "\r\n\0\"") !== false
            || !(str_starts_with($path, '/') || preg_match('~^[a-z]:[/\\\\]~i', $path) || str_starts_with($path, '\\\\'))) {
            throw new \InvalidArgumentException('Ein absoluter lokaler Pfad ist erforderlich.');
        }
        return $path;
    }
    public function local(string $path): string
    {
        $this->absolute($path);
        if (PHP_OS_FAMILY === 'Windows') { return $path; }
        if (preg_match('~^([a-z]):[/\\\\](.*)$~isD', $path, $parts)) {
            return '/mnt/'.strtolower($parts[1]).'/'.str_replace('\\', '/', $parts[2]);
        }
        if (preg_match('~^\\\\\\\\wsl\.localhost\\\\([^\\\\]+)\\\\(.*)$~iD', $path, $parts)
            || preg_match('~^\\\\\\\\wsl\$\\\\([^\\\\]+)\\\\(.*)$~iD', $path, $parts)) {
            $distribution = getenv('WSL_DISTRO_NAME');
            if ($distribution !== false && strcasecmp($distribution, $parts[1]) === 0) { return '/'.str_replace('\\', '/', $parts[2]); }
        }
        if (str_starts_with($path, '\\\\')) { throw new \DomainException('Dieser UNC-Pfad ist in dieser Laufzeit nicht eingebunden.'); }
        return $path;
    }
    public function identity(string $path): string
    {
        $local = $this->local($path);
        // Resolve existing parents too: missing leaves may still pass through symlinks.
        $tail = [];
        while (($resolved = realpath($local)) === false) {
            $parent = dirname($local);
            if ($parent === $local) { $resolved = $local; break; }
            array_unshift($tail, basename($local)); $local = $parent;
        }
        $normalized = rtrim(str_replace('\\', '/', $resolved), '/').'/'.implode('/', $tail);
        $prefix = str_starts_with($normalized, '//') ? '//' : (str_starts_with($normalized, '/') ? '/' : '');
        $parts = [];
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') { array_pop($parts); } elseif ($part !== '' && $part !== '.') { $parts[] = $part; }
        }
        $identity = $prefix.mb_convert_case(implode('/', $parts), MB_CASE_FOLD, 'UTF-8');
        return preg_match('/^[a-z]:$/D', $identity) ? $identity.'/' : $identity;
    }
    public function contains(string $directory, string $path): bool
    {
        $parent = $this->identity($directory); $child = $this->identity($path);
        return $parent === $child || str_starts_with($child, rtrim($parent, '/').'/');
    }
}
