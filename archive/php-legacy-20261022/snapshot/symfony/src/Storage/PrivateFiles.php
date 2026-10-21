<?php
declare(strict_types=1);
namespace App\Storage;

/** Checked local files; configuration backups contain credentials. */
final class PrivateFiles
{
    public static function read(string $path, int $maximum = 1048576): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) { throw new \DomainException('Konfigurationsdatei nicht lesbar.'); }
        try {
            $bytes = stream_get_contents($handle, $maximum + 1);
            if ($bytes === false || strlen($bytes) > $maximum) { throw new \DomainException('Konfigurationsdatei zu groß oder nicht lesbar.'); }
            return $bytes;
        } finally { fclose($handle); }
    }
    public static function object(string $bytes): \stdClass
    {
        $document = str_starts_with($bytes, "\xef\xbb\xbf") ? substr($bytes, 3) : $bytes;
        $value = json_decode($document, false, 512, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) { throw new \InvalidArgumentException('Ein JSON-Objekt ist erforderlich.'); }
        // PHP otherwise silently rounds integers outside its native range to floats.
        self::exactIntegers($value, json_decode($document, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
        return $value;
    }
    private static function exactIntegers(mixed $value, mixed $checked): void
    {
        if (is_float($value) && is_string($checked)) { throw new \InvalidArgumentException('Eine JSON-Ganzzahl liegt außerhalb des PHP-Ganzzahlbereichs. Die Datei wird nicht gerundet oder überschrieben.'); }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ($value as $key => $item) { self::exactIntegers($item, is_array($checked) ? $checked[$key] : $checked->{$key}); }
        }
    }
    public static function json(\stdClass $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
    public static function write(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (is_link($path) || !is_dir($directory)) { throw new \DomainException('Ziel muss eine lokale Datei in einem vorhandenen Ordner sein.'); }
        $temporary = tempnam($directory, '.indy-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) { throw new \RuntimeException('Temporäre Datei nicht anlegbar.'); }
        try {
            chmod($temporary, 0600); $handle = fopen($temporary, 'wb');
            if ($handle === false) { throw new \RuntimeException('Temporäre Datei nicht beschreibbar.'); }
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                    throw new \RuntimeException('Datei nicht vollständig geschrieben.');
                }
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) { throw new \RuntimeException('Dateiaustausch fehlgeschlagen.'); }
        } finally { if (file_exists($temporary)) { unlink($temporary); } }
    }
    public static function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))) {
            throw new \DomainException('Privater Datenordner nicht anlegbar.');
        }
    }
}
