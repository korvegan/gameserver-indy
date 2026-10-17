<?php
declare(strict_types=1);
namespace App\Tests;

use App\Runtime\RuntimeEvents;
use App\Runtime\RuntimeSignals;
use App\Job\JobService;
use PHPUnit\Framework\Attributes\Group;

final class RuntimeEventsTest extends SliceTestCase
{
    public function testSleepingRealWorkerReceivesNewlyCommittedJob(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Production workers run in WSL.'); }
        $jobs = $this->ledger(); $signals = static::getContainer()->get(RuntimeSignals::class); $listener = $signals->listen();
        $php = getenv('SLICE_PHP') ?: PHP_BINARY;
        $worker = new \Symfony\Component\Process\Process([$php, 'bin/console', 'messenger:consume', 'async',
            '--limit=1', '--time-limit=8', '--sleep=0', '--no-interaction'], dirname(__DIR__),
            ['APP_ENV'=>'test', 'SLICE_STATE_DIR'=>$this->directory, 'SLICE_DRIVER'=>'simulated', 'SLICE_ROLE'=>'owner', 'INDY_CACHE_DIR'=>$this->directory.'/worker-cache']);
        try {
            $worker->start(); $deadline = microtime(true)+5;
            while (count(glob(dirname($listener[1]).'/*.sock'))<2 && $worker->isRunning() && microtime(true)<$deadline) { usleep(20000); }
            self::assertTrue($worker->isRunning(), $worker->getErrorOutput());
            self::assertCount(2, glob(dirname($listener[1]).'/*.sock'), 'Worker subscribed before its first queue read.');
            usleep(50000);
            $job = static::getContainer()->get(JobService::class)->submit('demo-a', 'start', str_repeat('b', 32));
            $worker->setTimeout(5)->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertSame('succeeded', $jobs->get($job['id'])['state'], $worker->getOutput().$worker->getErrorOutput());
        } finally { $worker->stop(1); $signals->close($listener); }
    }
    #[Group('regular')]
    public function testCommittedJobsAndDeduplicatedGameEventsWakeListeners(): void
    {
        $this->ledger(); $container = static::getContainer();
        $signals = $container->get(RuntimeSignals::class); $listener = $signals->listen();
        $workerListener = $signals->listen(['jobs']);
        try {
            $signals->publish('logs', 'demo-a');
            if (PHP_OS_FAMILY === 'Linux') {
                self::assertSame([], $signals->wait($workerListener, .01), 'Log noise must not wake an idle job worker.');
                $signals->wait($listener, 0);
            }
            $container->get(JobService::class)->submit('demo-a', 'start', str_repeat('a', 32));
            if (PHP_OS_FAMILY === 'Linux') { self::assertSame('jobs', $signals->wait($listener, .5)[0]['topic']); }
            $events = new RuntimeEvents($container->get(\Doctrine\DBAL\Connection::class), $signals);
            $events->append('demo-a', 'first-session', 'session.ready');
            $events->append('demo-a', 'first-session', 'session.ready');
            self::assertCount(1, $events->pending());
            $events->acknowledge((int)$events->pending()[0]['sequence']);
            self::assertSame([], $events->pending());
            self::assertCount(1, $events->recent('demo-a'));
            if (PHP_OS_FAMILY === 'Linux') { self::assertCount(1, $signals->wait($listener, .5)); }
            if (PHP_OS_FAMILY === 'Linux') {
                $start = microtime(true);
                self::assertSame([], $signals->wait($listener, .03));
                self::assertGreaterThan(.02, microtime(true)-$start, 'An idle subscriber must block instead of spinning.');
            }
        } finally { $signals->close($listener); $signals->close($workerListener); }
    }
}
