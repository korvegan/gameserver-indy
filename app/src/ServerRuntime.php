<?php
declare(strict_types=1);

namespace IndyNext;

/** The command and connection values are private operator configuration, never HTTP input. */
final class ServerRuntime
{
    private array $settings;
    private FixtureStore $store;
    private AccessStore $access;

    public static function createFixture(string $directory, string $php): void
    {
        $value = ['kind'=>'fixture', 'php'=>$php, 'bridge_directory'=>$directory,
            'bridge_command'=>[$php, dirname(__DIR__).'/tests/game-peer.php', $directory],
            'connections'=>['enshrouded'=>null, 'aska'=>null]];
        $handle = fopen($directory.'/runtime.json', 'x');
        if ($handle === false) { throw new \RuntimeException('runtime_create_failed'); }
        try {
            chmod($directory.'/runtime.json', 0600);
            $data = json_encode($value, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $data) !== strlen($data)) { throw new \RuntimeException('runtime_create_failed'); }
        } finally { fclose($handle); }
    }

    public function __construct(private readonly string $directory)
    {
        $this->store = new FixtureStore($directory);
        $this->access = new AccessStore($directory);
        $path = $directory.'/runtime.json';
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600 || filesize($path)>65536) {
            throw new \RuntimeException('private_runtime_required');
        }
        $s = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($s) || !in_array($s['kind'] ?? null, ['fixture','windows'], true)
            || !is_string($s['php'] ?? null) || !str_starts_with($s['php'], '/') || !is_executable($s['php'])
            || !is_string($s['bridge_directory'] ?? null) || !is_array($s['bridge_command'] ?? null)
            || !array_is_list($s['bridge_command']) || !$s['bridge_command'] || !is_array($s['connections'] ?? null)) {
            throw new \RuntimeException('invalid_runtime');
        }
        new WindowsBridge($s['bridge_directory'], $s['bridge_command']);
        foreach (['enshrouded','aska'] as $id) {
            $value = $s['connections'][$id] ?? null;
            if ($value !== null && (!is_string($value) || $value === '' || strlen($value)>254
                || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value)
                || ($id === 'enshrouded' && !preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?):([1-9][0-9]{0,4})$/iD', $value))
                || ($id === 'enshrouded' && (int) substr(strrchr($value, ':'), 1)>65535))) {
                throw new \RuntimeException('invalid_connection');
            }
        }
        $this->settings = $s;
        $this->checkRelease();
    }

    public function nativeEnabled(): bool { return $this->settings['kind'] === 'windows'; }
    public function mode(): string { return $this->store->mode(); }

    private function checkRelease(): void
    {
        if ($this->mode() !== 'production') { return; }
        $revision = @file_get_contents(dirname(__DIR__, 2).'/REVISION');
        if ($this->settings['kind'] !== 'windows' || !is_string($revision)
            || !preg_match('/^[a-f0-9]{40}\n$/D', $revision)
            || @file_get_contents($this->directory.'/active-release') !== $revision) {
            throw new \RuntimeException('inactive_release');
        }
    }

    private function admit(callable $action): mixed
    {
        if ($this->mode() !== 'production') { return $action(); }
        $path = $this->directory.'/admission.lock';
        $lock = fopen($path, 'ce');
        if ($lock === false || !chmod($path, 0600)) { throw new \RuntimeException('admission_failed'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('admission_failed'); }
            $this->checkRelease();
            if (file_exists($this->directory.'/maintenance')) { throw new \DomainException('maintenance'); }
            return $action();
        } finally { fclose($lock); }
    }

    public function snapshot(Principal $actor): array
    {
        $this->access->authorize($actor, 'read');
        $result = [];
        $maintenance = $this->mode() === 'production' && file_exists($this->directory.'/maintenance');
        foreach ($this->store->snapshot() as $server) {
            if (!in_array($server['id'], $actor->servers, true)) { continue; }
            $id = $server['id'];
            $operation = $this->store->latest($id);
            $age = $server['observed_at'] === null ? null : microtime(true) - (float) (new \DateTimeImmutable($server['observed_at']))->format('U.u');
            $fresh = $age !== null && $age >= -5 && $age <= 30;
            $active = $operation !== null && in_array($operation['state'], ['pending','running','unknown'], true);
            $status = $server['process'] === 'running' ? 'Server läuft' : 'Server gestoppt';
            $detail = $server['process'] === 'stopped' ? 'Nicht spielbereit' : match ($server['ready']) {
                true=>'Spielbereit', false=>'Noch nicht spielbereit', null=>'Spielbereitschaft unbekannt',
            };
            $tone = $server['process'] === 'running' ? 'running' : 'stopped';
            if ($server['process'] === 'unknown') { $status='Status unbekannt'; $detail='Der Status wird geprüft.'; $tone='unknown'; }
            elseif (!$fresh) { $status='Status veraltet'; $detail='Bitte prüfe den aktuellen Status.'; $tone='unknown'; }
            if ($active) {
                if ($operation['state'] === 'unknown') {
                    $status='Ausgang unklar'; $detail='Der Ausgang der Aktion ist noch nicht bestätigt.'; $tone='unknown';
                } else {
                    $status=$operation['action'] === 'start' ? 'Start läuft …' : 'Stopp läuft …';
                    $detail=$operation['action'] === 'start' ? 'Der Start wird geprüft.' : 'Der Stopp wird geprüft.';
                    $tone='pending';
                }
            } elseif ($operation !== null && in_array($operation['state'], ['failed','cancelled'], true)) {
                $status=$operation['state'] === 'cancelled' ? 'Aktion abgebrochen'
                    : ($operation['action'] === 'start' ? 'Start fehlgeschlagen' : 'Stopp fehlgeschlagen');
                $detail=$server['process'] === 'unknown' ? 'Status unbekannt' : (!$fresh ? 'Status veraltet'
                    : ($server['process'] === 'stopped' ? 'Server gestoppt' : 'Server läuft'));
                $tone='error';
            }
            $connection = $fresh && $server['process'] === 'running' && !$active
                ? ($this->settings['connections'][$id] ?? null) : null;
            $result[] = [...$server, 'name'=>$id === 'aska' ? 'ASKA' : 'Enshrouded', 'status'=>$status,
                'detail'=>$maintenance ? 'Die Verwaltung wird aktualisiert.' : $detail, 'tone'=>$tone,
                'can_start'=>!$maintenance && $fresh && !$active && $server['process'] === 'stopped',
                'can_stop'=>!$maintenance && $fresh && !$active && $server['process'] === 'running',
                'connection'=>$connection, 'connection_label'=>$id === 'aska' ? 'Servername' : 'Beitrittsadresse',
                'connection_help'=>$id === 'aska' ? 'In ASKA in der Serverliste nach dem Servernamen suchen.' : 'Im Spiel nach Adresse und Query-Port suchen.',
                'operation'=>$operation === null ? null : array_intersect_key($operation, array_flip(['id','action','state']))];
        }
        return $result;
    }

    public function request(Principal $actor, string $server, string $action): array
    {
        if (!in_array($server, ['aska','enshrouded'], true) || !in_array($action, ['start','stop'], true)) {
            throw new \DomainException('invalid_form');
        }
        $this->access->authorize($actor, $action, $server);
        $this->store->recover($server);
        $current = array_values(array_filter($this->snapshot($actor), fn ($s) => $s['id'] === $server))[0];
        $op = $current['operation'];
        $sameActive = $op !== null && in_array($op['state'], ['pending','running'], true) && $op['action'] === $action;
        if (!$sameActive && !$current['can_'.$action]) { throw new \DomainException('server_busy'); }
        $operation = $this->admit(function () use ($actor, $server, $action): array {
            $operation = (new AuthorizedOperations($this->access, $this->store))->reserve($actor, $server, $action);
            $this->launch($server, $operation['id']);
            return $operation;
        });
        return array_intersect_key($operation, array_flip(['id','server','action','state']));
    }

    public function refresh(Principal $actor): void
    {
        $this->access->authorize($actor, 'read');
        $this->admit(function () use ($actor): void { foreach ($actor->servers as $server) {
            $this->store->recover($server);
            if ($this->store->claimRefresh($server)) {
                try { $this->launch($server, 'status'); }
                catch (\Throwable $e) { $this->store->releaseRefresh($server); throw $e; }
            }
        } });
    }

    private function launch(string $server, string $job): void
    {
        // setsid's parent exits immediately; only the bounded one-shot executor continues.
        $command = ['/usr/bin/setsid', '--fork', $this->settings['php'], dirname(__DIR__).'/bin/execute', $this->directory, $server, $job];
        if ($this->mode() === 'production') {
            $command = ['/bin/sh','-c','exec 9>&-; exec "$@"','indy-job',...$command];
        }
        $process = proc_open($command, [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes,
            null, array_intersect_key(getenv(), array_flip(['PATH','HOME','LANG','LC_ALL','TMPDIR','WSL_INTEROP','WSL_DISTRO_NAME'])));
        if (!is_resource($process) || proc_close($process) !== 0) { throw new \RuntimeException('executor_unavailable'); }
    }

    public function execute(string $server, string $job): void
    {
        if (!in_array($server, ['aska','enshrouded'], true) || ($job !== 'status' && !preg_match('/^[a-f0-9]{32}$/D', $job))) {
            throw new \InvalidArgumentException('invalid_job');
        }
        $lock = fopen($this->directory.'/'.($job === 'status' ? 'status-' : 'action-').$server.'.lock', 'ce');
        if ($lock === false) { throw new \RuntimeException('executor_unavailable'); }
        chmod($this->directory.'/'.($job === 'status' ? 'status-' : 'action-').$server.'.lock', 0600);
        $locked = false;
        try {
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            if (!$locked) { return; }
            $bridge = new WindowsBridge($this->settings['bridge_directory'], $this->settings['bridge_command']);
            if ($job === 'status') {
                $this->admit(fn () => null);
                $operation = $this->store->latest($server);
                $reconcile = $operation !== null && $operation['state'] === 'unknown';
                $reply = $bridge->call($server, 'status', $reconcile ? $operation['id'] : null);
                $this->observation($server, $reply);
                if ($reconcile && (($reply['outcome'] === 'succeeded' && $reply['error'] === '')
                    || ($reply['outcome'] === 'failed' && in_array($reply['error'], ['process_exited','console_unavailable','unsafe_console','process_changed'], true)))) {
                    $this->store->transition($operation['id'], $reply['outcome'] === 'succeeded' ? 'succeeded' : 'failed', $reply['error']);
                }
            } else {
                $operation = $this->store->operation($job);
                if ($operation['server'] !== $server) { throw new \RuntimeException('operation_mismatch'); }
                if ($operation['state'] !== 'pending') { return; }
                $this->admit(fn () => (new AuthorizedOperations($this->access, $this->store))->begin($job));
                $reply = $bridge->call($server, $operation['action'], $job);
                $this->observation($server, $reply);
                $state = match ($reply['outcome']) { 'succeeded'=>'succeeded', 'failed','busy'=>'failed', default=>'unknown' };
                $this->store->transition($job, $state, $reply['error']);
            }
        } catch (\Throwable $error) {
            if ($job !== 'status') {
                $operation = $this->store->operation($job);
                if ($operation['state'] === 'pending') { $this->store->transition($job, 'cancelled', 'executor_unavailable'); }
                elseif ($operation['state'] === 'running') { $this->store->transition($job, 'unknown', 'outcome_unresolved'); }
            }
        } finally {
            if ($locked && $job === 'status') { $this->store->releaseRefresh($server); }
            fclose($lock);
        }
    }

    private function observation(string $server, array $reply): void
    {
        $this->store->observe($server, $reply['process'], $reply['ready'], $reply['observed_at']);
    }
}
