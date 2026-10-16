<?php
declare(strict_types=1);
namespace App\Tests;

use App\Community\ChronicleValues;
use PHPUnit\Framework\TestCase;

final class ChronicleUnicodeTest extends TestCase
{
    public function testEveryUnicodeScalarAndCombiningSequenceMatchesTheOriginalUnicode16Contract(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/chronicle-unicode-16.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('16.0.0', $fixture['unicode_version']); $singletons = hash_init('sha256'); $sequences = hash_init('sha256');
        $add = function ($hash, string $value): void { $bytes = ChronicleValues::normalized($value); hash_update($hash, pack('N', strlen($bytes)).$bytes); };
        for ($point = 0; $point < 0x110000; ++$point) { if ($point < 0xd800 || $point > 0xdfff) { $add($singletons, mb_chr($point, 'UTF-8')); } }
        foreach ($fixture['combining_codepoints'] as $point) { $add($sequences, "A\u{0301}".mb_chr($point, 'UTF-8')."\u{0327}"); }
        self::assertSame($fixture['singletons_sha256'], hash_final($singletons)); self::assertSame($fixture['combining_sha256'], hash_final($sequences));
        self::assertSame(ChronicleValues::nameId('member-', 'Chris'), ChronicleValues::nameId('member-', 'ꟲhris'));
    }
}
