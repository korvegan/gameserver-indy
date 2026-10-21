<?php
declare(strict_types=1);
namespace App\Job;

/** Process-local context set only while this worker holds the execution lock. */
final class WorkerClaim
{
    private ?array $job = null;
    public function run(array $job, callable $operation): mixed
    {
        if ($this->job !== null) { throw new \LogicException('Verschachtelter Workerauftrag.'); }
        $this->job = $job;
        try { return $operation(); } finally { $this->job = null; }
    }
    public function forServer(string $id): ?array { return ($this->job['server_id'] ?? null) === $id ? $this->job : null; }
}
