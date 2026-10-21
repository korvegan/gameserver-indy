<?php
declare(strict_types=1);
namespace App\Log;
use App\Storage\UnicodeText;

final class LogText
{
    private const KEY = '(?:authentication[ _-]*token|auth[ _-]*token|access[ _-]*token|api[ _-]*key|password|passwd|webhook(?:[ _-]*url)?|authorization)';
    public static function decode(string $bytes, string $encoding = 'UTF-8'): string
    {
        $previous = mb_substitute_character(); mb_substitute_character(0xfffd);
        try { $text = mb_convert_encoding($bytes, 'UTF-8', $encoding); } finally { mb_substitute_character($previous); }
        return str_starts_with($text, "\xef\xbb\xbf") ? substr($text, 3) : $text;
    }
    public static function lines(string $text): array
    {
        $lines = preg_split('/\r\n|[\n\r\v\f\x1c-\x1e\x{0085}\x{2028}\x{2029}]/u', $text);
        if (end($lines) === '') { array_pop($lines); } return $lines;
    }
    public static function secrets(mixed $config, string $webhook = ''): array
    {
        $values = []; $visit = function (mixed $item) use (&$visit, &$values): void {
            if ($item instanceof \stdClass) {
                foreach ($item as $key => $value) {
                    if (preg_match('/'.self::KEY.'/iu', $key) && is_string($value) && $value !== '') { $values[] = $value; }
                    else { $visit($value); }
                }
            } elseif (is_array($item)) { foreach ($item as $value) { $visit($value); } }
        };
        $visit($config); if ($webhook !== '') { $values[] = $webhook; } $values = array_values(array_unique($values));
        usort($values, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); return $values;
    }
    public static function redact(string $text, array $values = []): string
    {
        $text = preg_replace('~https?://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v[0-9]+)?/webhooks/[^\s"\'<>]+~iu', '[ENTFERNT]', $text);
        $lines = []; $hideNext = false;
        foreach (self::lines($text) as $line) {
            if ($hideNext && UnicodeText::trim($line) !== '') { $lines[] = '[ENTFERNT]'; $hideNext = false; continue; }
            if (preg_match('/'.self::KEY.'/iu', $line, $match, PREG_OFFSET_CAPTURE)) {
                $end = $match[0][1] + strlen($match[0][0]); $suffix = substr($line, $end);
                if (preg_match('/[:=]/', $suffix, $delimiter, PREG_OFFSET_CAPTURE)) {
                    $value = substr($suffix, $delimiter[0][1] + 1); $hideNext = trim(UnicodeText::trim($value), "\"',") === '';
                    $line = substr($line, 0, $end).': [ENTFERNT]';
                }
            }
            foreach ($values as $value) { $line = preg_replace('/'.preg_quote($value, '/').'/iu', '[ENTFERNT]', $line); }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
    public static function tail(string $path, array $values = [], int $limit = 200): string
    {
        $handle = @fopen($path, 'rb'); if ($handle === false) { throw new \DomainException('Protokoll nicht lesbar.'); }
        try {
            $prefix = fread($handle, 2); $size = fstat($handle)['size']; $offset = max(0, $size - 262144); $encoding = 'UTF-8';
            if (in_array($prefix, ["\xff\xfe", "\xfe\xff"], true)) { $offset -= $offset % 2; $encoding = $prefix === "\xff\xfe" ? 'UTF-16LE' : 'UTF-16BE'; }
            fseek($handle, $offset); $text = self::decode(fread($handle, 262144), $encoding);
        } finally { fclose($handle); }
        if ($offset > 0) { $position = strpos($text, "\n"); $text = $position === false ? '' : substr($text, $position + 1); }
        return implode("\n", array_slice(self::lines(self::redact($text, $values)), -$limit));
    }
}
