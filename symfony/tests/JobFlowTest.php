<?php
declare(strict_types=1);

namespace App\Tests;

use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\OutcomeUnknown;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class JobFlowTest extends SliceTestCase
{
    public function testAcceptanceIsDurableFifoAndRedeliveryCannotReplayCommands(): void
    {
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $calls = [];
        $control = new class($calls) implements ServerControl {
            public function __construct(public array &$calls) {}
            public function status(string $serverId): array { return ['running' => false, 'ready' => false, 'pid' => null]; }
            public function execute(string $serverId, string $operation): void { $this->calls[] = [$serverId, $operation]; }
        };
        $first = $service->submit('demo-a', 'start', str_repeat('a', 32));
        $second = $service->submit('demo-a', 'stop', str_repeat('b', 32));
        $other = $service->submit('demo-b', 'restart', str_repeat('c', 32));
        self::assertSame('queued', $first['state']);
        self::assertSame(3, static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertSame($first['id'], $service->submit('demo-a', 'start', str_repeat('a', 32))['id']);
        $handler = new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control, static::getContainer()->get(\App\Access\AccessPolicy::class));
        $handler(new ExecuteServerJob($second['id'])); // Out-of-order delivery still drains FIFO.
        self::assertSame([['demo-a', 'start'], ['demo-a', 'stop']], $calls);
        self::assertSame('queued', $jobs->get($other['id'])['state']);
        $handler(new ExecuteServerJob($first['id']));
        $handler(new ExecuteServerJob($second['id']));
        self::assertCount(2, $calls);
        self::assertSame('succeeded', $jobs->get($second['id'])['state']);
        $handler(new ExecuteServerJob($other['id']));
        self::assertSame(['demo-b', 'restart'], $calls[2]);
    }

    public function testUnknownOutcomeInterruptsFollowersAndIsNotRepeated(): void
    {
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $calls = 0;
        $control = new class($calls) implements ServerControl {
            public function __construct(public int &$calls) {}
            public function status(string $serverId): array { return []; }
            public function execute(string $serverId, string $operation): void { ++$this->calls; throw new OutcomeUnknown('Ausgang unklar.'); }
        };
        $first = $service->submit('demo-a', 'restart', str_repeat('a', 32));
        $second = $service->submit('demo-a', 'start', str_repeat('b', 32));
        $handler = new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control, static::getContainer()->get(\App\Access\AccessPolicy::class));
        $handler(new ExecuteServerJob($first['id']));
        $handler(new ExecuteServerJob($first['id']));
        $handler(new ExecuteServerJob($second['id']));
        self::assertSame(1, $calls);
        self::assertSame('interrupted', $jobs->get($first['id'])['state']);
        self::assertSame('interrupted', $jobs->get($second['id'])['state']);
    }

    public function testPreviousWorkerDeathIsRecoveredWithoutAnotherSideEffect(): void
    {
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $first = $service->submit('demo-a', 'restart', str_repeat('a', 32));
        $second = $service->submit('demo-a', 'stop', str_repeat('b', 32));
        $jobs->transition($first['id'], 'running', 'Worker lost after claim.');
        $control = $this->createMock(ServerControl::class);
        $control->expects(self::never())->method('execute');
        $handler = new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control, static::getContainer()->get(\App\Access\AccessPolicy::class));
        $handler(new ExecuteServerJob($first['id']));
        self::assertSame('interrupted', $jobs->get($first['id'])['state']);
        self::assertSame('interrupted', $jobs->get($second['id'])['state']);
    }

    public function testSameRequestCannotBecomeAnotherCommand(): void
    {
        $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $service->submit('demo-a', 'start', str_repeat('a', 32));
        $this->expectException(\DomainException::class);
        $service->submit('demo-a', 'restart', str_repeat('a', 32));
    }

    public function testQueueHasAnExplicitBound(): void
    {
        $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        for ($index = 0; $index < 8; ++$index) { $service->submit('demo-a', 'start', sprintf('%032x', $index)); }
        $this->expectException(\DomainException::class);
        $service->submit('demo-a', 'stop', str_repeat('f', 32));
    }

    public function testOperationAndDeliveryRollbackTogether(): void
    {
        $jobs = $this->ledger();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('DROP TABLE messenger_messages');
        try { static::getContainer()->get(JobService::class)->submit('demo-a', 'start', str_repeat('a', 32)); self::fail('Expected missing transport failure'); }
        catch (\Throwable $error) { self::assertNotEmpty($error->getMessage()); }
        self::assertSame([], $jobs->recent());
        self::assertSame([], $jobs->events(0));
    }

    public function testAnotherProfileWorksWhileOneProfileIsLocked(): void
    {
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $runtime = static::getContainer()->get(SliceRuntime::class);
        $first = $service->submit('demo-a', 'start', str_repeat('a', 32));
        $other = $service->submit('demo-b', 'start', str_repeat('b', 32));
        $calls = [];
        $control = new class($calls) implements ServerControl {
            public function __construct(public array &$calls) {}
            public function status(string $serverId): array { return []; }
            public function execute(string $serverId, string $operation): void { $this->calls[] = $serverId; }
        };
        $handler = new ExecuteServerJobHandler($jobs, $runtime, $control, static::getContainer()->get(\App\Access\AccessPolicy::class));
        $lock = (new LockFactory(new FlockStore($runtime->directory.'/locks')))->createLock('execute:demo-a');
        self::assertTrue($lock->acquire());
        try {
            try { $handler(new ExecuteServerJob($first['id'])); self::fail('Locked target must wait'); }
            catch (RecoverableMessageHandlingException) { self::assertSame('queued', $jobs->get($first['id'])['state']); }
            $handler(new ExecuteServerJob($other['id']));
            self::assertSame(['demo-b'], $calls);
        } finally { $lock->release(); }
        $handler(new ExecuteServerJob($first['id']));
        self::assertSame(['demo-b', 'demo-a'], $calls);
    }

    public function testStorageFailureAfterSideEffectDoesNotReplayIt(): void
    {
        $jobs = $this->ledger();
        $service = static::getContainer()->get(JobService::class);
        $first = $service->submit('demo-a', 'restart', str_repeat('a', 32));
        $second = $service->submit('demo-a', 'start', str_repeat('b', 32));
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_success BEFORE UPDATE OF state ON slice_jobs WHEN NEW.state='succeeded' BEGIN SELECT RAISE(FAIL, 'test storage failure'); END");
        $calls = 0;
        $control = new class($calls) implements ServerControl {
            public function __construct(public int &$calls) {}
            public function status(string $serverId): array { return []; }
            public function execute(string $serverId, string $operation): void { ++$this->calls; }
        };
        $handler = new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control, static::getContainer()->get(\App\Access\AccessPolicy::class));
        try { $handler(new ExecuteServerJob($first['id'])); self::fail('Expected storage error'); }
        catch (RecoverableMessageHandlingException) { self::assertSame('running', $jobs->get($first['id'])['state']); }
        $db->executeStatement('DROP TRIGGER fail_success');
        $handler(new ExecuteServerJob($first['id']));
        self::assertSame(1, $calls);
        self::assertSame('interrupted', $jobs->get($first['id'])['state']);
        self::assertSame('interrupted', $jobs->get($second['id'])['state']);
    }
}
