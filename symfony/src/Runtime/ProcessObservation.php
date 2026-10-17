<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Instance\InstanceRegistry;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** One observer per target; HTTP only projects this bounded, expiring snapshot. */
final class ProcessObservation
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly ProcessGateway $gateway, private readonly Readiness $readiness, private readonly PerformanceHistory $performance,
        private readonly RuntimeSignals $signals) {}
    public function refresh(string $serverId, int $validFor = 30): void
    {
        $profile = $this->registry->get($serverId);
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('observation:'.$serverId);
        if (!$lock->acquire()) { return; }
        try {
            $fingerprint = $this->fingerprint($profile);
            $path = $this->runtime->directory.'/observations/'.$serverId.'.json';
            $previous = is_file($path) ? hash_file('sha256', $path) : null;
            try { $status = $this->gateway->call($serverId, 'Status'); }
            catch (\Throwable) { $status = ['known' => false, 'running' => null, 'ready' => null, 'pid' => null]; }
            $this->commit($serverId, $status, $fingerprint, true, $previous, $validFor);
        } finally { $lock->release(); }
    }
    public function record(string $serverId, array $status, ?string $fingerprint = null): void
    {
        $fingerprint ??= $this->fingerprint($this->registry->get($serverId));
        $this->commit($serverId, $status, $fingerprint, false, null, 30);
    }
    private function commit(string $serverId, array $status, string $fingerprint, bool $compare, ?string $previous, int $validFor): void
    {
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('observation-write:'.$serverId); $lock->acquire(true);
        try {
            $folder = $this->runtime->directory.'/observations'; PrivateFiles::directory($folder);
            $path = $folder.'/'.$serverId.'.json';
            if ($compare && (is_file($path) ? hash_file('sha256', $path) : null) !== $previous) { return; }
            try { $old = (array)PrivateFiles::object(PrivateFiles::read($path))->status; } catch (\Throwable) { $old = []; }
            $evidence = $this->readiness->probe($serverId,$status);
            $evidence['revision'] = hash('sha256',json_encode([$evidence['process_key'] ?? null,$evidence['state'],
                $evidence['source'] ?? null,$evidence['transition'] ?? null,$evidence['evidence_at'] ?? null],JSON_THROW_ON_ERROR));
            $evidence['observed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $status['readiness'] = $evidence;
            $status['ready'] = $evidence['state'] === 'ready' ? true : ($evidence['state'] === 'stopped' ? false : null);
            PrivateFiles::write($path, PrivateFiles::json((object) [
                // Equal status and timestamps still represent a newer completed observation/action.
                'generation' => bin2hex(random_bytes(16)),
                'fingerprint' => $fingerprint, 'observed_at' => time(), 'valid_for'=>max(30,min(360,$validFor)), 'status' => (object) $status]));
            try { $this->performance->record($serverId,$status); } catch (\Throwable) { /* Optional history cannot fail a server action. */ }
            $fields = array_flip(['known','running','starting','pid','ready']);
            $oldRevision = ((array)($old['readiness'] ?? []))['revision'] ?? null;
            $changed = array_intersect_key($old,$fields) !== array_intersect_key($status,$fields) || $oldRevision !== $evidence['revision'];
            $this->signals->publish($changed ? 'status' : 'performance', $serverId);
        } finally { $lock->release(); }
    }
    public function view(string $serverId): array
    {
        $profile = $this->registry->get($serverId); $unknown = ['known' => false, 'running' => null, 'ready' => null, 'pid' => null];
        $path = $this->runtime->directory.'/observations/'.$serverId.'.json';
        if (!is_file($path)) { return $unknown; }
        try {
            $cached = PrivateFiles::object(PrivateFiles::read($path, 65536));
            if (($cached->fingerprint ?? '') !== $this->fingerprint($profile) || !is_int($cached->observed_at ?? null)
                || time() - $cached->observed_at > max(30,min(360,$cached->valid_for ?? 30)) || time() < $cached->observed_at || !($cached->status ?? null) instanceof \stdClass) { return $unknown; }
            $status = get_object_vars($cached->status);
            if (isset($status['readiness'])) { $status['readiness'] = (array) $status['readiness']; }
            return [...$status, 'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $cached->observed_at)];
        } catch (\Throwable) { return $unknown; }
    }
    private function fingerprint(\stdClass $profile): string
    {
        return hash('sha256', PrivateFiles::json($profile));
    }
}
