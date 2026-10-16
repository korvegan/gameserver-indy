<?php
declare(strict_types=1);

namespace App\Tests;

use App\Job\JobReconciler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\SimulatedServerControl;
use App\Runtime\SliceRuntime;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class WorkerRecoveryTest extends SliceTestCase
{
    public static function terminalFailures(): array { return [['succeeded'], ['failed']]; }

    #[DataProvider('terminalFailures')]
    public function testRealWorkerRetainsDeliveryAndHttpRecoversWithoutReplay(string $terminal): void
    {
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'SERVER_PORT' => '18865', 'REMOTE_ADDR' => '127.0.0.1']);
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $first = $service->submit('demo-a', 'start', str_repeat('a', 32));
        $second = $service->submit('demo-a', 'stop', str_repeat('b', 32));
        $db = static::getContainer()->get(Connection::class);
        if ($terminal === 'failed') { mkdir($this->directory.'/simulated/demo-a.json'); }
        $db->executeStatement("CREATE TRIGGER fail_terminal BEFORE UPDATE OF state ON slice_jobs WHEN NEW.state='$terminal' BEGIN SELECT RAISE(FAIL, 'test storage failure'); END");
        $this->consume(); // Actual Messenger failure/retry handling, not manual handler redelivery.
        self::assertSame('running', $jobs->get($first['id'])['state']);
        self::assertSame('queued', $jobs->get($second['id'])['state']);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'), 'Failed delivery must remain recoverable');
        $db->executeStatement('DROP TRIGGER fail_terminal');
        $client->request('GET', '/api/jobs?id='.$first['id']);
        self::assertResponseIsSuccessful();
        self::assertSame('interrupted', json_decode($client->getResponse()->getContent(), true)['job']['state']);
        self::assertSame('interrupted', $jobs->get($second['id'])['state']);
        $this->consume(); // A follower delivery cannot repeat Start or execute Stop.
        $runtime = static::getContainer()->get(SliceRuntime::class);
        self::assertSame($terminal === 'succeeded', (new SimulatedServerControl($runtime))->status('demo-a')['running']);
        self::assertSame(3, (int) $jobs->get($first['id'])['revision']);
    }

    public function testFailureBeforeClaimRemainsDeliverable(): void
    {
        $jobs = $this->ledger();
        $job = static::getContainer()->get(JobService::class)->submit('demo-a', 'start', str_repeat('a', 32));
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_claim BEFORE UPDATE OF state ON slice_jobs WHEN NEW.state='running' BEGIN SELECT RAISE(FAIL, 'test claim failure'); END");
        $this->consume();
        self::assertSame('queued', $jobs->get($job['id'])['state']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $db->executeStatement('DROP TRIGGER fail_claim');
        usleep(1100000);
        $this->consume();
        self::assertSame('succeeded', $jobs->get($job['id'])['state']);
    }

    public function testReconciliationWaitsForActiveWorkerButNeedsNoTransportRedelivery(): void
    {
        $jobs = $this->ledger();
        $job = static::getContainer()->get(JobService::class)->submit('demo-a', 'restart', str_repeat('a', 32));
        $runtime = static::getContainer()->get(SliceRuntime::class);
        $lock = (new LockFactory(new FlockStore($runtime->directory.'/locks')))->createLock('execute:demo-a');
        self::assertTrue($lock->acquire());
        $reconciler = static::getContainer()->get(JobReconciler::class);
        try {
            $jobs->transition($job['id'], 'running', 'Owned by worker.');
            static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM messenger_messages');
            $reconciler->reconcile();
            self::assertSame('running', $jobs->get($job['id'])['state']);
        } finally { $lock->release(); }
        $reconciler->reconcile();
        self::assertSame('interrupted', $jobs->get($job['id'])['state']);
    }

    public function testSimulatorStorageFailureCannotReportSuccess(): void
    {
        $jobs = $this->ledger();
        $job = static::getContainer()->get(JobService::class)->submit('demo-b', 'start', str_repeat('a', 32));
        mkdir($this->directory.'/simulated/demo-b.json');
        $this->consume();
        self::assertSame('failed', $jobs->get($job['id'])['state']);
        self::assertSame([], glob($this->directory.'/simulated/demo-b.json.*'));
    }

    public function testEventStreamSettlesOrphanWithoutAnotherCommandOrSnapshot(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'SERVER_PORT' => '18865', 'REMOTE_ADDR' => '127.0.0.1']);
        $jobs = $this->ledger();
        $job = static::getContainer()->get(JobService::class)->submit('demo-a', 'restart', str_repeat('a', 32));
        $jobs->transition($job['id'], 'running', 'Worker lost.');
        $cursor = $jobs->snapshot()['cursor'];
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM messenger_messages');
        $client->request('GET', '/api/job-events?after='.$cursor);
        self::assertResponseIsSuccessful();
        $stream = $client->getInternalResponse()->getContent();
        self::assertStringContainsString('event: job', $stream);
        self::assertStringContainsString('"state":"interrupted"', $stream);
        self::assertSame('interrupted', static::getContainer()->get(JobRepository::class)->get($job['id'])['state']);
    }

    private function consume(): void
    {
        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('messenger:consume'));
        self::assertSame(0, $command->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 5, '--sleep' => 0]));
    }
}
