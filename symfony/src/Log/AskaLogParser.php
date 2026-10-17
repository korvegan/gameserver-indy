<?php
declare(strict_types=1);
namespace App\Log;

use App\Community\ChronicleTime;

final class AskaLogParser
{
    public function parse(string $line, \DateTimeImmutable $started, array &$context): ?string
    {
        if (!preg_match('/^(\S+) The session is now open!\s*$/D', $line, $match)) { return null; }
        try { $date = ChronicleTime::parse($match[1]); } catch (\Throwable) { return null; }
        return $date >= $started && $date <= new \DateTimeImmutable() ? 'session.ready' : null;
    }
}
