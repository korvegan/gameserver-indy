<?php
declare(strict_types=1);
namespace App\Storage;

final class UnicodeText
{
    /** Preserve the existing configuration format's Unicode whitespace semantics. */
    public static function trim(string $text): string
    {
        return preg_replace('/(?:\A[\p{Z}\x09-\x0d\x1c-\x1f\x{0085}]+|[\p{Z}\x09-\x0d\x1c-\x1f\x{0085}]+\z)/u', '', $text)
            ?? throw new \InvalidArgumentException('Ungültiger UTF-8-Text.');
    }
}
