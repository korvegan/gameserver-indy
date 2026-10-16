<?php
declare(strict_types=1);
namespace App\Storage;

/** Preserve Python 3.14's ipaddress classification, including IANA exceptions. */
final class IPv4
{
    public static function address(mixed $value, bool $public = false): ?string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return null; }
        [$a, $b, $c, $d] = array_map(intval(...), explode('.', $value));
        if ($value === '0.0.0.0' || $a === 127 || $a >= 224 || $a === 169 && $b === 254) { return null; }
        if ($public && !in_array($value, ['192.0.0.9', '192.0.0.10'], true)
            && ($a === 0 || $a === 10 || $a === 100 && $b >= 64 && $b <= 127
                || $a === 172 && $b >= 16 && $b <= 31 || $a === 192 && ($b === 168 || $b === 0 && in_array($c, [0,2], true))
                || $a === 198 && (in_array($b, [18,19], true) || $b === 51 && $c === 100)
                || $a === 203 && $b === 0 && $c === 113)) { return null; }
        return $value;
    }
}
