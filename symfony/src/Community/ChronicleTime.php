<?php
declare(strict_types=1);
namespace App\Community;

/** The existing Python ISO input contract, without changing stored evidence strings. */
final class ChronicleTime
{
    private static function invalid(): never { throw new \InvalidArgumentException('Ereigniszeit benötigt ein gültiges Datum mit Zeitzone.'); }
    private static function separator(string $value): int
    {
        $length = strlen($value); if ($length < 7) { self::invalid(); } if ($length === 7) { return 7; }
        if ($value[4] === '-') {
            if ($value[5] !== 'W') { return 10; }
            if ($length > 8 && $value[8] === '-') {
                if ($length === 9) { self::invalid(); }
                return $length > 10 && ctype_digit($value[10]) ? 8 : 10;
            }
            return 8;
        }
        if ($value[4] !== 'W') { return 8; }
        $index = 7; while ($index < $length && ctype_digit($value[$index])) { ++$index; }
        return $index < 9 ? $index : ($index % 2 === 0 ? 7 : 8);
    }
    private static function components(string $value): array
    {
        $length = strlen($value); $parts = [0, 0, 0, 0]; $position = 0; $separated = false;
        for ($component = 0; $component < 3; ++$component) {
            $digits = substr($value, $position, 2); if (strlen($digits) !== 2 || !ctype_digit($digits)) { self::invalid(); }
            $parts[$component] = (int) $digits; $position += 2; $next = $value[$position] ?? '';
            if ($component === 0) { $separated = $next === ':'; }
            if ($next === '' || $component === 2) { break; }
            if ($separated && $next !== ':') { self::invalid(); } $position += (int) $separated;
        }
        if ($position < $length) {
            if (!in_array($value[$position], ['.', ','], true) || !ctype_digit(substr($value, $position + 1))) { self::invalid(); }
            $parts[3] = (int) str_pad(substr($value, $position + 1, 6), 6, '0');
        }
        return $parts;
    }
    public static function parse(string $value): \DateTimeImmutable
    {
        if (!mb_check_encoding($value, 'UTF-8')) { self::invalid(); }
        $separator = self::separator($value); $date = substr($value, 0, $separator); $zone = new \DateTimeZone('UTC');
        if (preg_match('/^([0-9]{4})(-?)([0-9]{2})\2([0-9]{2})$/D', $date, $parts)) {
            $year = (int) $parts[1]; $month = (int) $parts[3]; $day = (int) $parts[4];
            if ($year < 1 || !checkdate($month, $day, $year)) { self::invalid(); }
            $base = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $zone);
        } elseif (preg_match('/^([0-9]{4})(-?)W([0-9]{2})(?:\2([1-7]))?$/D', $date, $parts)) {
            $year = (int) $parts[1]; $week = (int) $parts[3]; $day = (int) ($parts[4] ?? 1);
            if ($year < 1 || $week < 1 || $week > 53) { self::invalid(); }
            $base = (new \DateTimeImmutable('2000-01-01', $zone))->setISODate($year, $week, $day);
            if ((int) $base->format('o') !== $year || (int) $base->format('W') !== $week) { self::invalid(); }
        } else { self::invalid(); }
        if ((int) $base->format('Y') < 1 || (int) $base->format('Y') > 9999) { self::invalid(); }
        // Python accepts any single Unicode date/time separator.
        $time = mb_substr(substr($value, $separator), 1, null, 'UTF-8'); $zoneAt = strcspn($time, '+-Z');
        if ($zoneAt === strlen($time)) { self::invalid(); }
        [$hour, $minute, $second, $microsecond] = self::components(substr($time, 0, $zoneAt)); $offset = 0;
        if ($hour > 24 || $minute > 59 || $second > 59 || ($hour === 24 && ($minute || $second || $microsecond))) { self::invalid(); }
        if ($time[$zoneAt] === 'Z') { if ($zoneAt !== strlen($time) - 1) { self::invalid(); } }
        else {
            [$hours, $minutes, $seconds, $micros] = self::components(substr($time, $zoneAt + 1));
            // Python treats a zero whole-second offset as UTC, including a fraction.
            if ($hours || $minutes || $seconds) { $offset = (($hours * 60 + $minutes) * 60 + $seconds) * 1000000 + $micros; }
            if ($offset >= 86400000000) { self::invalid(); } if ($time[$zoneAt] === '-') { $offset = -$offset; }
        }
        $base = $base->setTime($hour % 24, $minute, $second, $microsecond); if ($hour === 24) { $base = $base->modify('+1 day'); }
        if ((int) $base->format('Y') < 1 || (int) $base->format('Y') > 9999) { self::invalid(); }
        $result = $offset === 0 ? $base : $base->modify(sprintf('%+d microseconds', -$offset));
        if ((int) $result->format('Y') < 1 || (int) $result->format('Y') > 9999) { self::invalid(); }
        return $result;
    }
}
