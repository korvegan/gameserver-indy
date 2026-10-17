<?php
declare(strict_types=1);
namespace App\Log;

final class EnshroudedLogParser
{
    public function parse(string $line, \DateTimeImmutable $started, array &$context): ?string
    {
        if (preg_match('/Current UTC date: (\d{4}\.\d{2}\.\d{2} \d{2}:\d{2}:\d{2})/', $line, $match)) {
            $date = \DateTimeImmutable::createFromFormat('!Y.m.d H:i:s', $match[1], new \DateTimeZone('UTC'));
            $context['current'] = $date && in_array($date->getTimestamp()-$started->getTimestamp(), [0,1], true);
            // A copied log starts numbering at the same UTC header. Persisted event keys
            // then suppress its historical transitions, including multiple up/down pairs.
            if ($context['current']) { $context['transition'] = 0; unset($context['last_type']); }
            return null;
        }
        if (($context['current'] ?? false) && preg_match("/\\[Session\\] 'HostOnline' \\((up|down)\\)!/", $line, $match)) {
            return $match[1] === 'up' ? 'session.ready' : 'session.closed';
        }
        return null;
    }
}
