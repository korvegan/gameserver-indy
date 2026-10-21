<?php
declare(strict_types=1);

namespace App\Job;

final readonly class ExecuteServerJob
{
    public function __construct(public string $jobId) {}
}
