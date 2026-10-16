<?php
declare(strict_types=1);
namespace App\Community;

use App\Storage\UnicodeText;

/** Preserve existing identity, timestamp and bounded JPEG contracts. */
final class ChronicleValues
{
    public const IMAGE_LIMIT = 262144;
    public const EXPORT_LIMIT = 33554432;
    public static function text(mixed $value, int $maximum, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0b-\x1f]/u', $value)) { throw new \InvalidArgumentException('Ein Chroniktext ist ungültig oder zu lang.'); }
        $value = UnicodeText::trim($value);
        if ($required && $value === '') { throw new \InvalidArgumentException('Bitte alle Pflichtfelder des Moments ausfüllen.'); }
        return $value;
    }
    public static function assertUnicodeRuntime(): void
    {
        if (!extension_loaded('intl') || \IntlChar::getUnicodeVersion() < [16, 0, 0, 0]) { throw new \RuntimeException('Chronikidentitäten benötigen natives Intl mit Unicode mindestens 16.'); }
    }
    public static function normalized(string $value): string
    {
        self::assertUnicodeRuntime();
        // Preserve the existing Python Unicode-16 identity contract even on newer ICU.
        // Code points unassigned in 16 are normalization boundaries and stay literal.
        $result = ''; $segment = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            $age = \IntlChar::charAge($character);
            if ($age[0] === 0 || $age > [16, 0, 0, 0]) {
                $result .= mb_convert_case(\Normalizer::normalize($segment, \Normalizer::FORM_KC), MB_CASE_FOLD, 'UTF-8').$character; $segment = '';
            } else { $segment .= $character; }
        }
        return $result.mb_convert_case(\Normalizer::normalize($segment, \Normalizer::FORM_KC), MB_CASE_FOLD, 'UTF-8');
    }
    public static function nameId(string $prefix, string $value): string { return $prefix.substr(hash('sha256', self::normalized($value)), 0, 24); }
    private static function parse(string $value): \DateTimeImmutable
    {
        return ChronicleTime::parse($value);
    }
    public static function occurred(string $value): string
    {
        if ($value === '') { return ''; }
        $moment = self::parse($value);
        return $moment->format('Y-m-d\TH:i:s').($moment->format('u') === '000000' ? '' : '.'.$moment->format('u')).'+00:00';
    }
    public static function time(mixed $value, mixed $fallback): string
    {
        foreach ([$value, $fallback] as $candidate) {
            if (!is_string($candidate)) { continue; }
            try { return self::parse($candidate)->format('Y-m-d\TH:i:s.uP'); } catch (\Throwable) {}
        }
        return '0001-01-01T00:00:00.000000+00:00';
    }
    public static function date(string $value, bool $next = false): string
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($moment === false || $moment->format('Y-m-d') !== $value || (int) $moment->format('Y') < 1
            || ($next && $value === '9999-12-31')) { throw new \InvalidArgumentException('Zeitraum bitte als gültiges Datum angeben.'); }
        return ($next ? $moment->modify('+1 day') : $moment)->format('Y-m-d\TH:i:s.uP');
    }
    public static function jpeg(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 4 || $length > self::IMAGE_LIMIT || !str_starts_with($bytes, "\xff\xd8") || !str_ends_with($bytes, "\xff\xd9")) {
            throw new \InvalidArgumentException('Das Vorschaubild muss ein JPEG mit höchstens 256 KiB sein.');
        }
        for ($offset = 2; $offset + 4 <= $length;) {
            if (ord($bytes[$offset]) !== 255) { break; }
            $marker = ord($bytes[$offset + 1]); $size = unpack('n', substr($bytes, $offset + 2, 2))[1];
            if ($size < 2 || $offset + 2 + $size > $length) { break; }
            if (in_array($marker, [0xc0, 0xc1, 0xc2], true)) {
                if ($size < 8) { break; }
                [$height, $width] = array_values(unpack('n2', substr($bytes, $offset + 5, 4)));
                if ($width >= 1 && $width <= 1280 && $height >= 1 && $height <= 1280) { return [$bytes, $width, $height]; }
                break;
            }
            $offset += 2 + $size;
        }
        throw new \InvalidArgumentException('Das JPEG-Vorschaubild hat ungültige Abmessungen (maximal 1280 × 1280).');
    }
    public static function blueprint(string $game): string { return 'landscape-v1-'.(['enshrouded' => 'mountains', 'aska' => 'woodland', 'raft' => 'coast'][$game] ?? 'meadow'); }
    public static function event(array $row): array
    {
        $decoded = json_decode($row['payload'], flags: JSON_THROW_ON_ERROR); $event = get_object_vars($decoded); $position = (int) $row['position'];
        $scene = ['id' => $row['scene_id'], 'position' => $position, 'template' => $row['template'],
            'blueprint' => $row['template'] === 'milestone' ? self::blueprint($row['original_game']) : $row['template'],
            'chapter' => intdiv($position, 6), 'slot' => $position % 6];
        return [...$event, 'recorded_at' => $row['recorded_at'], 'scene' => $scene,
            'awards' => array_map(fn ($member) => ['member_id' => $member, 'badge_id' => $event['badge_id']], $event['member_ids'])];
    }
    /** Canonical semantic comparison also accepts historical Python whitespace/float encoding. */
    public static function canonical(mixed $value): string
    {
        if ($value instanceof \stdClass) { $properties = get_object_vars($value); ksort($properties, SORT_STRING); $value = (object) array_map(fn ($item) => json_decode(self::canonical($item)), $properties); }
        elseif (is_array($value)) { $value = array_map(fn ($item) => json_decode(self::canonical($item)), $value); }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
    /** Python json.dumps(ensure_ascii=False, sort_keys=True) keeps rollback retries byte-compatible. */
    public static function payload(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value); ksort($properties, SORT_STRING); $items = [];
            foreach ($properties as $key => $item) { $items[] = self::payload((string) $key).': '.self::payload($item); }
            return '{'.implode(', ', $items).'}';
        }
        if (is_array($value)) { return '['.implode(', ', array_map(self::payload(...), $value)).']'; }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    }
}
