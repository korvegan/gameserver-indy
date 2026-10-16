<?php
declare(strict_types=1);
namespace App\WhatsApp;

use App\Community\ChronicleTime;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Log\LogText;
use App\Storage\PrivateFiles;

/** Bounded current-start evidence. Local readiness never establishes external reachability. */
final class Readiness
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games, private readonly NativePaths $paths) {}
    public static function identity(array $status): ?array
    {
        $processes = $status['processes'] ?? [];
        if (!is_array($processes) || count($processes) !== 1) { return null; }
        $process = (array) $processes[0];
        if (!is_int($process['pid'] ?? null) || !is_string($process['started'] ?? null)) { return null; }
        try { $started = ChronicleTime::parse($process['started']); if ($started > new \DateTimeImmutable()) { return null; } }
        catch (\Throwable) { return null; }
        return [$process['pid'].':'.$process['started'], $started];
    }
    public function probe(string $id, array $status): array
    {
        $identity = self::identity($status);
        if ($identity === null) {
            $state = !empty($status['starting']) ? 'starting' : (($status['running'] ?? null) === true ? 'unknown' : (($status['running'] ?? null) === false ? 'stopped' : 'unknown'));
            return ['state' => $state, 'label' => ['starting' => 'Starter arbeitet', 'unknown' => 'Unbekannt', 'stopped' => 'Gestoppt'][$state]];
        }
        [$key, $started] = $identity; $result = ['state' => 'unknown', 'label' => 'Bereitschaft noch nicht bestätigt', 'process_key' => $key];
        try {
            $profile = $this->registry->get($id);
            if ($profile->game === 'enshrouded') {
                $config = $this->games->adapter($profile->game)->decode(PrivateFiles::read($this->registry->configPath($profile)));
                $directory = ($profile->log_directory ?? '') ?: ($config->logDirectory ?? './logs');
                if (!str_starts_with($directory, '/') && !preg_match('~^[a-z]:[/\\\\]|^\\\\\\\\~i', $directory)) { $directory = rtrim($profile->server_directory, '/\\').'/'.$directory; }
                $path = $this->paths->local($directory).'/enshrouded_server.log'; $file = @fopen($path, 'rb');
                if ($file === false) { return $result; }
                try {
                    $head = fread($file, 262144); $length = strlen($head); $size = fstat($file)['size']; $gap = false;
                    if ($size > $length) {
                        $offset = max($length, $size - 262144); $gap = $offset > $length; fseek($file, $offset); $tail = fread($file, 262144);
                        if ($gap) { $position = strpos($tail, "\n"); $tail = "\n".($position === false ? '' : substr($tail, $position+1)); }
                        $head .= $tail;
                    }
                } finally { fclose($file); }
                // Byte offsets keep transition boundaries intact even with multibyte text.
                preg_match_all('/Current UTC date: ([0-9]{4}\.[0-9]{2}\.[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})/', $head, $headers, PREG_OFFSET_CAPTURE);
                if (!$headers[0]) { return $result; } $index = count($headers[0])-1;
                $logStart = \DateTimeImmutable::createFromFormat('!Y.m.d H:i:s', $headers[1][$index][0], new \DateTimeZone('UTC'));
                if (!$logStart || (\DateTimeImmutable::getLastErrors()['warning_count'] ?? 0)) { return $result; }
                $difference = $logStart->getTimestamp() - $started->getTimestamp(); $from = $headers[0][$index][1] + strlen($headers[0][$index][0]);
                if ($gap) { $from = max($from, $length+1); }
                preg_match_all("/\\[Session\\] 'HostOnline' \\((up|down)\\)!/", substr($head, $from), $transitions);
                $ready = in_array($difference, [0,1], true) && $transitions[1] && end($transitions[1]) === 'up';
                $source = 'Enshrouded · HostOnline / Startdatum UTC';
            } elseif ($profile->game === 'aska') {
                $file = @fopen($this->registry->diagnosticPath($profile).'/server.log', 'rb'); if ($file === false) { return $result; }
                try { fseek($file, max(0, fstat($file)['size'] - 262144)); $text = LogText::decode(fread($file, 262144)); } finally { fclose($file); }
                $ready = false;
                foreach (LogText::lines($text) as $line) {
                    if (!str_contains($line, 'The session is now open!')) { continue; }
                    $stamp = ChronicleTime::parse(explode(' ', $line, 2)[0]);
                    if ($stamp >= $started && $stamp <= new \DateTimeImmutable()) { $ready = true; }
                }
                $source = 'ASKA · aktuelle Sitzungsöffnung';
            } else { return $result; }
            if ($ready) { $result = [...$result, 'state' => 'ready', 'label' => 'Sitzung bereit · lokal bestätigt', 'source' => $source]; }
        } catch (\Throwable) {}
        return $result;
    }
}
