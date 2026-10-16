<?php
declare(strict_types=1);
namespace App\Instance;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Job\JobRepository;
use App\Job\WorkerClaim;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Uses the same acceptance and execution locks as lifecycle jobs. */
final class InstanceMutation
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs,
        private readonly ServerControl $control, private readonly AccessPolicy $policy,
        private readonly WorkerClaim $claim, private readonly RuntimeLifecycle $lifecycle) {}
    public function stopped(Principal $actor, string $id, string $permission, callable $change): mixed
    {
        return $this->reserved($actor, $id, $permission, function (callable $guard) use ($id, $change): mixed {
            $pending = $this->runtime->directory.'/data/world-backups/'.$id.'/restore-pending.json';
            if (file_exists($pending) || is_link($pending)) { throw new \DomainException('Eine Weltwiederherstellung benötigt zuerst die ausdrückliche Rückkehr zum gesicherten Stand.'); }
            try { $status = $this->control->status($id); }
            catch (\InvalidArgumentException) { throw new \DomainException('Für dieses Profil ist der native Prozessadapter noch nicht aktiviert.'); }
            if (($status['running'] ?? null) !== false || ($status['starting'] ?? null) !== false
                || ($status['launcher_running'] ?? null) !== false) { throw new \DomainException('Server und Starter müssen eindeutig gestoppt sein.'); }
            $guard(); return $change($status);
        });
    }
    public function reserved(Principal $actor, string $id, string $permission, callable $change, bool $lifecycleFollowers = false): mixed
    {
        $this->policy->require($actor, $permission, $id); $this->runtime->assertInitialized();
        $factory = new LockFactory(new FlockStore($this->runtime->directory.'/locks'));
        $acceptance = $factory->createLock('acceptance'); $execution = $factory->createLock('execute:'.$id);
        $current = $this->claim->forServer($id);
        if ($current === null && !$acceptance->acquire()) { throw new \DomainException('Eine Auftragsannahme läuft. Bitte erneut versuchen.'); }
        try {
            if ($current === null && !$execution->acquire()) { throw new \DomainException('Dieses Serverprofil arbeitet bereits.'); }
            try {
                $this->policy->require($actor, $permission, $id);
                $fence = $current ?? ['runtime_epoch' => $this->lifecycle->epoch()];
                $guard = function () use ($fence, $actor, $permission, $id): void {
                    $this->lifecycle->requireClaim($fence); $this->policy->require($actor, $permission, $id);
                };
                $guard();
                $followersAllowed = $lifecycleFollowers && in_array($current['operation'] ?? '', ['stop', 'restart'], true);
                if (!$followersAllowed && $this->jobs->pendingCount($id, 'control') !== ($current === null ? 0 : 1)) { throw new \DomainException('Für dieses Profil stehen noch Aufträge aus.'); }
                return $change($guard);
            } finally { if ($current === null) { $execution->release(); } }
        } finally { if ($current === null) { $acceptance->release(); } }
    }
}
