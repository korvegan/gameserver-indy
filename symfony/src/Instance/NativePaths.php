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
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $resolved.'/'.implode('/', $tail))) as $part) {
            if ($part === '..') { array_pop($parts); } elseif ($part !== '' && $part !== '.') { $parts[] = $part; }
        }
        return mb_convert_case(implode('/', $parts), MB_CASE_FOLD, 'UTF-8');
    }
}
