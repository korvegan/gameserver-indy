<?php
declare(strict_types=1);
namespace App\Runtime;

/** Linux /proc identities distinguish dead/reused PIDs without invoking process-name tools. */
final class LinuxProcess
{
    public static function identity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (!is_string($stat) || ($end = strrpos($stat, ')')) === false) { return null; }
        $fields = explode(' ', substr($stat, $end + 2));
        if (count($fields) < 20 || in_array($fields[0], ['Z','X'], true)) { return null; }
        return ['pid'=>$pid, 'start'=>$fields[19], 'group'=>(int) $fields[2]];
    }
    public static function alive(array $identity): bool
    {
        $current = self::identity((int) ($identity['pid'] ?? 0));
        return $current !== null && ($identity['start'] ?? null) === $current['start'];
    }
    public static function groupAlive(int $group): bool
    {
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $identity = self::identity((int) basename(dirname($path)));
            if (($identity['group'] ?? null) === $group) { return true; }
        }
        return false;
    }
}
