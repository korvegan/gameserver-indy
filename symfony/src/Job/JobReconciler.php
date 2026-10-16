<?php
declare(strict_types=1);

namespace App\Job;

use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** A transport may have lost its delivery; the durable ledger still owns the outcome. */
final class JobReconciler
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime) {}

    public function reconcile(): void
    {
        foreach ($this->jobs->pendingTargets() as $serverId) {
            if (!$this->jobs->hasRunning($serverId)) { continue; }
            $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('execute:'.$serverId);
            if (!$lock->acquire()) { continue; }
            try {
                // A live worker holds this same lock through the terminal write.
                if ($this->jobs->hasRunning($serverId)) { $this->jobs->recover($serverId); }
            } finally { $lock->release(); }
        }
    }
}
