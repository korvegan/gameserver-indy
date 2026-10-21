<?php
declare(strict_types=1);
namespace App\Integration;

/** Bounded quoted KeyValues parser, including duplicate-key rejection. */
final class SteamKeyValues
{
    public const LIMIT = 4194304;
    public static function named(string $text, string $name): array
    {
        if (strlen($text) > self::LIMIT || !mb_check_encoding($text, 'UTF-8')) { throw new \DomainException('Steam-Metadaten zu groß oder nicht UTF-8-lesbar.'); }
        $text = str_starts_with($text, "\xef\xbb\xbf") ? substr($text, 3) : $text;
        preg_match_all('/^\s*"'.preg_quote($name, '/').'"\s*(?=\{)/m', $text, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0]) !== 1) { throw new \DomainException('Steam hat keine eindeutigen App-Informationen geliefert.'); }
        $position = $matches[0][0][1] + strlen($matches[0][0][0]);
        if (self::token($text, $position) !== ['{', true]) { throw new \DomainException('Steam-Metadaten enthalten kein Objekt.'); }
        return self::block($text, $position, 0);
    }
    private static function token(string $text, int &$position): array
    {
        while ($position < strlen($text)) {
            if (!preg_match('~\G(?:\s+|//[^\n]*|"((?:\\\\.|[^"\\\\])*)"|([{}]))~', $text, $match, PREG_UNMATCHED_AS_NULL, $position)) { break; }
            $position += strlen($match[0]);
            if ($match[1] !== null) { return [preg_replace('/\\\\(["\\\\])/', '$1', $match[1]), false]; }
            if (($match[2] ?? null) !== null) { return [$match[2], true]; }
        }
        throw new \DomainException('Steam-Metadaten sind unvollständig oder nicht lesbar.');
    }
    private static function block(string $text, int &$position, int $depth): array
    {
        if ($depth > 32) { throw new \DomainException('Steam-Metadaten sind zu tief verschachtelt.'); }
        $result = [];
        while (true) {
            [$key, $structural] = self::token($text, $position);
            if ($structural && $key === '}') { return $result; }
            $key = mb_convert_case($key, MB_CASE_FOLD, 'UTF-8');
            if ($structural || array_key_exists($key, $result)) { throw new \DomainException('Steam-Metadaten enthalten mehrdeutige Schlüssel.'); }
            [$value, $structural] = self::token($text, $position);
            if ($structural && $value !== '{') { throw new \DomainException('Steam-Metadaten sind unvollständig.'); }
            $result[$key] = $structural ? self::block($text, $position, $depth + 1) : $value;
        }
    }
    public static function branch(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $value)) { throw new \DomainException('Der Steam-Branch ist nicht gültig.'); }
        return in_array(strtolower($value), ['public', 'default'], true) ? 'public' : $value;
    }
    public static function build(mixed $value): bool { return is_string($value) && preg_match('/^[0-9]{1,20}$/D', $value) && trim($value, '0') !== ''; }
}
