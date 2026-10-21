<?php
declare(strict_types=1);

namespace App\Job;

use App\Integration\IntegrationOperations;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** A transport may have lost its delivery; the durable ledger still owns the outcome. */
final class JobReconciler
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime) {}

    public function reconcile(): void
    {
        foreach ($this->jobs->pendingResources() as ['server_id' => $serverId, 'lane' => $lane]) {
            if (!$this->jobs->hasRunning($serverId, $lane)) { continue; }
            $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock(IntegrationOperations::lock($serverId, $lane));
            if (!$lock->acquire()) { continue; }
            try {
                // A live worker holds this same lock through the terminal write.
                if ($this->jobs->hasRunning($serverId, $lane)) { $this->jobs->recover($serverId, $lane); }
            } finally { $lock->release(); }
        }
    }
}
