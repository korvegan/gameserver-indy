<?php
declare(strict_types=1);

namespace IndyNext;

/** Local executor transport, never an HTTP controller or an authorization decision. */
final class WindowsBridge
{
    public function __construct(private readonly string $directory, private readonly array $command)
    {
        if (!is_dir($directory) || is_link($directory) || $command === []) {
            throw new \InvalidArgumentException('invalid_bridge_configuration');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('invalid_bridge_configuration');
            }
        }
    }

    public function call(string $server, string $action, ?string $operation = null, float $timeout = 60): array
    {
        if (!in_array($server, ['enshrouded', 'aska'], true)
            || !in_array($action, ['status', 'start', 'stop'], true)
            || ($operation !== null && !preg_match('/^[a-f0-9]{32}$/D', $operation))
            || ($action !== 'status' && $operation === null) || !is_finite($timeout) || $timeout <= 0 || $timeout > 90) {
            throw new \InvalidArgumentException('invalid_bridge_request');
        }
        $request = ['version' => 1, 'server' => $server, 'action' => $action, 'operation' => $operation];
        $nonce = bin2hex(random_bytes(16));
        $requestFile = $this->directory.'/request-'.$nonce.'.json';
        $replyFile = $this->directory.'/reply-'.$nonce.'.json';
        $stream = fopen($requestFile, 'x');
        if ($stream === false) {
            throw new \RuntimeException('bridge_request_failed');
        }
        chmod($requestFile, 0600);
        try {
            $data = json_encode($request, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $data) !== strlen($data) || !fflush($stream)) {
                throw new \RuntimeException('bridge_request_failed');
            }
        } finally {
            fclose($stream);
        }
        $process = null;
        $finished = false;
        try {
            $process = proc_open([...$this->command, '-Request', $nonce],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return $this->uncertain($request, 'transport_failed');
            }
            $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    $finished = true;
                    break;
                }
                usleep(20_000);
            } while (hrtime(true) < $deadline);
            if (!$finished) {
                // Only our one-shot transport is cancelled; the independent game is never killed.
                proc_terminate($process);
                $grace = hrtime(true) + 100_000_000;
                while (proc_get_status($process)['running'] && hrtime(true) < $grace) {
                    usleep(10_000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                return $this->uncertain($request, 'transport_timeout');
            }
            if ($state['exitcode'] !== 0 || !is_file($replyFile) || is_link($replyFile)
                || filesize($replyFile) > 16384) {
                return $this->uncertain($request, 'invalid_reply');
            }
            $reply = json_decode(file_get_contents($replyFile), true, 16, JSON_THROW_ON_ERROR);
            return $this->validate($request, $reply);
        } catch (\Throwable) {
            return $this->uncertain($request, 'invalid_reply');
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            // A timed-out Windows call may still finish; its files and receipt remain for diagnosis.
            if ($finished) {
                @unlink($requestFile);
                @unlink($replyFile);
            }
        }
    }

    private function validate(array $request, mixed $reply): array
    {
        $fields = ['version','server','action','operation','outcome','error','process','ready','observed_at','identity'];
        if (!is_array($reply) || count($reply) !== count($fields) || array_diff($fields, array_keys($reply))
            || !in_array($reply['outcome'], ['succeeded','failed','unknown','busy'], true)
            || !in_array($reply['error'], ['', 'invalid_request','invalid_profile','owner_mismatch','process_unreadable',
                'ambiguous_process','world_mismatch','operation_mismatch','invalid_state','outcome_unresolved',
                'server_busy','unsupported_runtime','native_error','process_exited','process_changed',
                'console_unavailable','unsafe_console','stop_timeout'], true)
            || !in_array($reply['process'], ['unknown','stopped','running'], true)
            || ($reply['ready'] !== null && (!is_bool($reply['ready']) || $reply['process'] !== 'running'))
            || ($reply['observed_at'] !== null && !self::timestamp($reply['observed_at']))) {
            return $this->uncertain($request, 'invalid_reply');
        }
        foreach ($request as $key => $value) {
            if ($reply[$key] !== $value) {
                return $this->uncertain($request, 'invalid_reply');
            }
        }
        $identity = $reply['identity'];
        if (($reply['process'] === 'running' && (!is_array($identity) || count($identity) !== 2
                || !is_int($identity['pid'] ?? null) || $identity['pid'] < 1 || !self::timestamp($identity['started'] ?? null)))
            || ($reply['process'] !== 'running' && $identity !== null)
            || ($reply['process'] !== 'unknown' && $reply['observed_at'] === null)) {
            return $this->uncertain($request, 'invalid_reply');
        }
        return $reply;
    }

    private static function timestamp(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,7})?Z$/D', $value);
    }

    private function uncertain(array $request, string $error): array
    {
        return [...$request, 'outcome' => 'unknown', 'error' => $error, 'process' => 'unknown',
            'ready' => null, 'observed_at' => null, 'identity' => null];
    }
}
