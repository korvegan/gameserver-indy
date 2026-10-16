<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\Principal;
use App\Access\AccessStore;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\ManagedServerControl;
use App\Runtime\OutcomeUnknown;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\ServerControl;
use App\Storage\PrivateFiles;
use Doctrine\DBAL\Connection;

final class ManagedProcessTest extends SliceTestCase
{
    private function bootManaged(?ProcessGateway $gateway = null, bool $http = false): ?\Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $client = $http ? static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']) : null;
        if ($client === null) { static::bootKernel(); } else { $client->disableReboot(); }
        $this->ledger();
        if ($gateway === null) {
            $gateway = $this->createStub(ProcessGateway::class);
            $gateway->method('call')->willReturn($this->stopped());
        }
        static::getContainer()->set(ProcessGateway::class, $gateway);
        $directory = $this->directory.'/own-install'; PrivateFiles::directory($directory);
        static::getContainer()->get(InstanceRegistry::class)->register(new Principal('local-owner', 'owner'),
            (object) ['id' => 'fixture', 'game' => 'enshrouded', 'label' => 'Own fixture', 'server_directory' => $directory]);
        PrivateFiles::write($directory.'/enshrouded_server.json', PrivateFiles::json((new EnshroudedConfiguration())->initial()));
        return $client;
    }
    private function stopped(): array
    {
        return ['known' => true, 'running' => false, 'starting' => false, 'launcher_running' => false,
            'ready' => null, 'pid' => null, 'processes' => [], 'user_profile' => $this->directory.'/own-windows-user'];
    }
    public function testLifecycleMustBeOpenedAndRestartNeverReplaysOldCommands(): void
    {
        $this->bootManaged(); $container = static::getContainer(); $service = $container->get(JobService::class);
        try { $service->submit('fixture', 'start', str_repeat('a', 32)); self::fail('Inactive native runtime accepted'); }
        catch (\DomainException) { self::assertSame(0, $container->get(JobRepository::class)->pendingCount()); }
        $lifecycle = $container->get(RuntimeLifecycle::class); $firstEpoch = $lifecycle->open();
        $old = $service->submit('fixture', 'restart', str_repeat('a', 32));
        $newEpoch = $lifecycle->open(); self::assertNotSame($firstEpoch, $newEpoch);
        $control = $this->createMock(ServerControl::class); $control->expects(self::never())->method('execute');
        ($this->handler($control))(new ExecuteServerJob($old['id']));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($old['id'])['state']);
        $lifecycle->close();
        $this->expectException(\DomainException::class); $service->submit('fixture', 'stop', str_repeat('b', 32));
    }
    public function testShutdownInterruptsOtherTargetsBeforeTheyEnterAWorker(): void
    {
        $this->ledger(); $container = static::getContainer(); $lifecycle = $container->get(RuntimeLifecycle::class); $lifecycle->open();
        $jobs = $container->get(JobRepository::class); $service = $container->get(JobService::class);
        $first = $service->submit('demo-a', 'start', str_repeat('a', 32));
        $other = $service->submit('demo-b', 'start', str_repeat('b', 32));
        $lifecycle->close();
        $control = $this->createMock(ServerControl::class); $control->expects(self::never())->method('execute');
        $handler = $this->handler($control); $handler(new ExecuteServerJob($first['id'])); $handler(new ExecuteServerJob($other['id']));
        self::assertSame('interrupted', $jobs->get($other['id'])['state']);
        self::assertSame('interrupted', $jobs->get($first['id'])['state']);
    }
    public function testExclusiveConfigurationRunsInsideClaimWithoutLeakingPayload(): void
    {
        $this->bootManaged(); $container = static::getContainer(); $container->get(RuntimeLifecycle::class)->open();
        $service = $container->get(JobService::class); $jobs = $container->get(JobRepository::class);
        $path = $this->directory.'/own-install/enshrouded_server.json'; $bytes = PrivateFiles::read($path);
        $config = PrivateFiles::object($bytes); $config->name = 'Saved by worker'; $config->userGroups[0]->password = 'synthetic-secret-only-in-private-payload';
        $payload = (object) ['config' => $config, 'revision' => hash('sha256', $bytes)];
        $job = $service->submit('fixture', 'config', str_repeat('a', 32), null, $payload);
        try { $service->submit('fixture', 'start', str_repeat('b', 32)); self::fail('Lifecycle command crossed exclusive mutation'); }
        catch (\DomainException) { self::assertSame(1, $jobs->pendingCount('fixture')); }
        $handler = $container->get(ExecuteServerJobHandler::class); $handler(new ExecuteServerJob($job['id']));
        $result = JobRepository::public($jobs->get($job['id']));
        self::assertSame('succeeded', $result['state']); self::assertSame('Saved by worker', PrivateFiles::object(PrivateFiles::read($path))->name);
        self::assertArrayNotHasKey('payload', $result); self::assertArrayNotHasKey('runtime_epoch', $result);
        self::assertStringNotContainsString('synthetic-secret', json_encode($result));
        $db = $container->get(Connection::class);
        foreach ($db->fetchFirstColumn('SELECT payload FROM slice_events') as $event) { self::assertStringNotContainsString('synthetic-secret', $event); }
        self::assertSame($bytes, PrivateFiles::read($this->directory.'/data/servers/fixture/backups/'.$result['result']['backup']));
        $handler(new ExecuteServerJob($job['id'])); self::assertCount(1, glob($this->directory.'/data/servers/fixture/backups/*'));
    }
    public function testFailedStarterRemainsUnknownAndNeverForcesASecondStart(): void
    {
        $gateway = $this->createMock(ProcessGateway::class);
        $gateway->expects(self::once())->method('call')->with('fixture', 'Status')->willReturn([
            ...$this->stopped(), 'starting' => true, 'launcher_running' => true]);
        $this->bootManaged($gateway); $container = static::getContainer(); $container->get(RuntimeLifecycle::class)->open();
        $container->get(ManagedServerControl::class)->execute('fixture', 'start');
        self::assertTrue($container->get(ProcessObservation::class)->view('fixture')['starting']);
        self::assertNull($container->get(ProcessObservation::class)->view('fixture')['ready']);
    }
    public function testRestartDoesNotStartAfterRuntimeClosesDuringRegularStop(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $lifecycle = null; $calls = [];
        $gateway->expects(self::exactly(2))->method('call')->willReturnCallback(function ($id, $action) use (&$lifecycle, &$calls): array {
            $calls[] = $action;
            if ($action === 'Status') { return [...$this->stopped(), 'running' => true, 'pid' => 123, 'processes' => [['pid' => 123, 'started' => '2026-10-16T00:00:00Z']]]; }
            if ($action === 'Stop') { $lifecycle->close(); return $this->stopped(); }
            self::fail('Unexpected start after shutdown');
        });
        $this->bootManaged($gateway); $lifecycle = static::getContainer()->get(RuntimeLifecycle::class); $lifecycle->open();
        try { static::getContainer()->get(ManagedServerControl::class)->execute('fixture', 'restart'); self::fail('Restart succeeded after shutdown'); }
        catch (OutcomeUnknown) { self::assertSame(['Status', 'Stop'], $calls); }
    }
    public function testSlowObservationCannotOverwriteNewerActionAndStaleCacheIsUnknown(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $observation = null;
        $gateway->expects(self::once())->method('call')->willReturnCallback(function () use (&$observation): array {
            $path = $this->directory.'/observations/fixture.json';
            $before = PrivateFiles::object(PrivateFiles::read($path));
            $observation->record('fixture', $this->stopped()); // A newer completed Stop wins over this old pending observation.
            $after = PrivateFiles::object(PrivateFiles::read($path));
            self::assertSame($before->status->running, $after->status->running);
            self::assertNotSame($before->generation, $after->generation);
            return [...$this->stopped(), 'running' => true, 'pid' => 123];
        });
        $this->bootManaged($gateway); $observation = static::getContainer()->get(ProcessObservation::class);
        $observation->record('fixture', $this->stopped()); // Existing identical state must not hide a newer write.
        $observation->refresh('fixture'); self::assertFalse($observation->view('fixture')['running']);
        $path = $this->directory.'/observations/fixture.json'; $cache = PrivateFiles::object(PrivateFiles::read($path));
        $cache->observed_at = time() - 60; PrivateFiles::write($path, PrivateFiles::json($cache));
        self::assertFalse($observation->view('fixture')['known']); self::assertNull($observation->view('fixture')['running']);
    }
    public function testConfigurationDoesNotWriteAfterRuntimeClosesDuringNativeStatus(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $lifecycle = null;
        $gateway->expects(self::once())->method('call')->willReturnCallback(function () use (&$lifecycle): array {
            $lifecycle->close();
            return $this->stopped();
        });
        $this->bootManaged($gateway); $container = static::getContainer();
        $lifecycle = $container->get(RuntimeLifecycle::class); $lifecycle->open();
        $path = $this->directory.'/own-install/enshrouded_server.json'; $bytes = PrivateFiles::read($path);
        $config = PrivateFiles::object($bytes); $config->name = 'Must never be written';
        $job = $container->get(JobService::class)->submit('fixture', 'config', str_repeat('a', 32), null,
            (object) ['config' => $config, 'revision' => hash('sha256', $bytes)]);
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($job['id'])['state']);
        self::assertSame($bytes, PrivateFiles::read($path));
        self::assertSame([], glob($this->directory.'/data/servers/fixture/backups/*'));
    }
    public function testConfigurationRechecksPersonalAccessAfterNativeStatus(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $access = null;
        $owner = new Principal('local-owner', 'owner');
        $gateway->expects(self::once())->method('call')->willReturnCallback(function () use (&$access, $owner): array {
            $access->configure($owner, ['enabled' => false]);
            return $this->stopped();
        });
        $this->bootManaged($gateway); $container = static::getContainer();
        $container->get(RuntimeLifecycle::class)->open(); $access = $container->get(AccessStore::class);
        $access->configure($owner, ['enabled' => true, 'hostname' => 'fixture.example.ts.net']);
        $access->saveUser($owner, ['login' => 'synthetic@example.test', 'name' => 'Synthetic remote owner', 'role' => 'owner'], ['fixture']);
        $actor = $access->principal('synthetic@example.test');
        $path = $this->directory.'/own-install/enshrouded_server.json'; $bytes = PrivateFiles::read($path);
        $config = PrivateFiles::object($bytes); $config->name = 'Must never be written';
        $job = $container->get(JobService::class)->submit('fixture', 'config', str_repeat('a', 32), $actor,
            (object) ['config' => $config, 'revision' => hash('sha256', $bytes)]);
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($job['id'])['state']);
        self::assertSame($bytes, PrivateFiles::read($path));
        self::assertSame([], glob($this->directory.'/data/servers/fixture/backups/*'));
    }
    public function testHttpReadsOnlySharedStatusAndConfigurationAcceptanceDoesNotWaitForWindows(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::never())->method('call');
        $client = $this->bootManaged($gateway, true); $container = static::getContainer();
        $container->get(RuntimeLifecycle::class)->open();
        $client->request('GET', '/api/status?server=fixture'); self::assertResponseIsSuccessful();
        $unknown = json_decode($client->getResponse()->getContent(), true)['status'];
        self::assertNull($unknown['running']); self::assertFalse($unknown['known']);
        $container->get(ProcessObservation::class)->record('fixture', $this->stopped());
        $client->request('GET', '/api/status?server=fixture'); self::assertResponseIsSuccessful();
        $status = json_decode($client->getResponse()->getContent(), true)['status'];
        self::assertFalse($status['running']); self::assertArrayNotHasKey('user_profile', $status);
        $client->request('GET', '/api/session'); $session = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['fixture'], array_keys($session['servers']));
        $client->request('GET', '/api/config?server=fixture'); $config = json_decode($client->getResponse()->getContent(), true);
        $config['config']['unknown'] = ['nested' => str_repeat('synthetic-data-', 1000)]; $config['request_id'] = str_repeat('a', 32);
        $client->request('POST', '/api/config', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865',
            'HTTP_X_MANAGER_TOKEN' => $session['token']], content: json_encode($config));
        self::assertResponseStatusCodeSame(202);
        $public = json_decode($client->getResponse()->getContent(), true)['job'];
        self::assertSame('queued', $public['state']); self::assertArrayNotHasKey('payload', $public);
        self::assertStringNotContainsString('synthetic-data-', json_encode($public));
    }
    public function testOpeningAnotherEpochRefusesAWorkerThatStillOwnsItsProcessLock(): void
    {
        $this->ledger(); $container = static::getContainer(); $lifecycle = $container->get(RuntimeLifecycle::class);
        $epoch = $lifecycle->open(); $jobs = $container->get(JobRepository::class);
        $job = $container->get(JobService::class)->submit('demo-a', 'start', str_repeat('a', 32));
        $jobs->transition($job['id'], 'running', 'Owned by a worker');
        $lock = (new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($this->directory.'/locks')))->createLock('execute:demo-a');
        self::assertTrue($lock->acquire());
        try {
            try { $lifecycle->open(); self::fail('Active worker adopted'); }
            catch (\DomainException) { self::assertSame($epoch, $lifecycle->epoch()); self::assertSame('running', $jobs->get($job['id'])['state']); }
        } finally { $lock->release(); }
        $lifecycle->open(); self::assertSame('interrupted', $jobs->get($job['id'])['state']);
    }
}
