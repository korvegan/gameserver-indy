<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessPolicy;
use App\Access\AccessStore;
use App\Access\Principal;
use App\Game\AskaConfiguration;
use App\Game\EnshroudedConfiguration;
use App\Game\GameCatalog;
use App\Instance\InstanceConfiguration;
use App\Instance\InstanceMutation;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Instance\WorldBinding;
use App\Job\JobRepository;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ConfigurationTest extends SliceTestCase
{
    private function boot(): void { static::bootKernel(); $this->ledger(); }
    private function owner(): Principal { return new Principal('local-owner', 'owner'); }
    private function registry(): InstanceRegistry { return static::getContainer()->get(InstanceRegistry::class); }
    private function profile(string $id = 'enshrouded', string $game = 'enshrouded', array $extra = []): \stdClass
    {
        $directory = $this->directory.'/install-'.$id; PrivateFiles::directory($directory);
        return (object) ['id' => $id, 'game' => $game, 'label' => 'Fixture '.$id, 'server_directory' => $directory, ...$extra];
    }
    private function configuration(\stdClass $profile, ?\stdClass $config = null): string
    {
        $adapter = static::getContainer()->get(GameCatalog::class)->adapter($profile->game);
        $path = $this->registry()->configPath($profile); PrivateFiles::write($path, $adapter->encode($config ?? $adapter->initial(), ''));
        return $path;
    }
    private function service(array $status = ['running' => false, 'starting' => false, 'launcher_running' => false]): InstanceConfiguration
    {
        $container = static::getContainer(); $control = $this->createMock(ServerControl::class);
        $control->method('status')->willReturn($status); $control->expects(self::never())->method('execute');
        $mutation = new InstanceMutation($container->get(SliceRuntime::class), $container->get(JobRepository::class), $control, $container->get(AccessPolicy::class),
            $container->get(\App\Job\WorkerClaim::class), $container->get(\App\Runtime\RuntimeLifecycle::class));
        return new InstanceConfiguration($this->registry(), $container->get(GameCatalog::class), $container->get(AccessPolicy::class), $mutation,
            $container->get(NativePaths::class), $container->get(\App\Instance\InstanceWorldGuard::class), $container->get(SliceRuntime::class));
    }
    public function testEnshroudedRetainsUnknownNestedJsonTypesAndValidatesOnePointZeroBounds(): void
    {
        $adapter = new EnshroudedConfiguration(); $config = $adapter->initial();
        $config->unknown = (object) ['empty_object' => new \stdClass(), 'empty_array' => [], 'ratio' => 1.0, 'steam_id' => 76561198000000000];
        $config->gameSettings->future = (object) ['setting' => false];
        $adapter->validate($config); $bytes = $adapter->encode($config, '');
        self::assertEquals($config, $adapter->decode("\xef\xbb\xbf".$bytes));
        self::assertInstanceOf(\stdClass::class, $adapter->decode($bytes)->unknown->empty_object);
        self::assertIsFloat($adapter->decode($bytes)->unknown->ratio);
        try { $adapter->decode('{"future_integer":9223372036854775808}'); self::fail('Overflowing integer rounded'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $config->gameSettings->flameLevelFogRemoval = 0; $adapter->validate($config);
        $config->gameSettings->flameLevelFogRemoval = 20; $adapter->validate($config);
        foreach ([21, -1, true, 1.0, null] as $value) {
            $config->gameSettings->flameLevelFogRemoval = $value;
            try { $adapter->validate($config); self::fail('Invalid 1.0 option accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testAskaKeepsBomCommentsUnknownSettingsLineEndingsAndHiddenSecrets(): void
    {
        $adapter = new AskaConfiguration();
        $before = "\xef\xbb\xbf# comment\r\n server name  = Old\r\n; keep me\r\nunknown = a=b\r\npassword = synthetic-secret\r\nauthentication token = synthetic-token\r\n";
        $previous = $adapter->decode($before); $submitted = clone $previous; $submitted->{'server name'} = 'Neu';
        $submitted->password = ''; $submitted->{'authentication token'} = '';
        $merged = $adapter->merge($submitted, $previous); $after = $adapter->encode($merged, $before);
        self::assertSame(str_replace('= Old', '= Neu', $before), $after);
        self::assertSame('', $adapter->publicConfiguration($merged)->password);
        self::assertSame('synthetic-secret', $merged->password);
        self::assertSame('a=b', $adapter->decode($after)->unknown);
        foreach (["name = a\nname = b\n", "broken line\n", " = x\n", "\xff"] as $invalid) {
            try { $adapter->decode($invalid); self::fail('Invalid ASKA document accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $config = $adapter->initial(); $config->password = null;
        try { $adapter->validate($adapter->merge($config, $previous)); self::fail('Null hid bad secret type'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
    }
    public function testAskaExternalLauncherBindsSaveIdAndStartNeedsItsOwnToken(): void
    {
        $adapter = new AskaConfiguration(); $before = $adapter->initial(); $before->{'save id'} = 'fixed-42';
        $after = clone $before; $after->{'save id'} = 'different';
        try { $adapter->validateEdit($after, $before, (object) ['launcher_script' => 'C:\\fixture.ps1']); self::fail('World changed'); } catch (\DomainException) { self::assertTrue(true); }
        try { $adapter->validateStart($before); self::fail('Missing token accepted'); } catch (\DomainException) { self::assertTrue(true); }
        $before->{'authentication token'} = 'fixture'; $adapter->validateStart($before); $before->mode = 'custom';
        $this->expectException(\DomainException::class); $adapter->validateStart($before);
    }
    public function testImportPreservesExactMetadataAndDoesNotOverwriteOrTouchGameFiles(): void
    {
        $this->boot(); $profile = $this->profile('aska', 'aska', ['config_path' => $this->directory.'/external properties.txt',
            'launcher_script' => $this->directory.'/fixture.ps1', 'log_directory' => $this->directory.'/logs']);
        $bytes = "\xef\xbb\xbf".PrivateFiles::json((object) ['schema_version' => 2, 'servers' => [$profile],
            'discord' => (object) ['webhook_url' => 'synthetic-private'], 'future_metadata' => (object) ['nested' => []],
            'catalog_games' => [(object) ['id' => 'raft', 'label' => 'Raft']]]);
        $source = $this->directory.'/source.json'; PrivateFiles::write($source, $bytes);
        $manifest = $this->registry()->import($source);
        self::assertSame(hash('sha256', $bytes), $manifest['sha256']);
        self::assertSame($bytes, PrivateFiles::read($this->directory.'/manager.local.json'));
        self::assertFileDoesNotExist($profile->config_path);
        $catalog = $this->registry()->catalog($this->owner()); self::assertSame('raft', $catalog['games'][2]['id']);
        self::assertSame('unsupported', $catalog['games'][2]['capabilities']['configuration']['state']);
        try { $this->registry()->import($source); self::fail('Import overwritten'); } catch (\DomainException) { self::assertSame($bytes, PrivateFiles::read($source)); }
        $this->registry()->saveConnection($this->owner(), 'aska', '');
        self::assertSame('synthetic-private', $this->registry()->settings()->discord->webhook_url);
        self::assertEquals((object) ['nested' => []], $this->registry()->settings()->future_metadata);
    }
    public function testLegacySingleInstanceAndPathIdentitiesRemainCompatible(): void
    {
        $this->boot(); $registry = $this->registry(); $profile = $this->profile();
        $value = $registry->validate((object) ['server_directory' => $profile->server_directory]);
        self::assertSame('enshrouded', $value->servers[0]->id);
        $paths = new NativePaths(); self::assertSame($paths->identity($profile->server_directory), $paths->identity($profile->server_directory.'/../'.basename($profile->server_directory)));
        if (PHP_OS_FAMILY !== 'Windows') { self::assertSame('/mnt/d/SteamLibrary/Test', $paths->local('D:\\SteamLibrary\\Test')); }
        $registry->register($this->owner(), $profile);
        try { $registry->register($this->owner(), (object) [...get_object_vars($profile), 'id' => 'duplicate', 'server_directory' => $profile->server_directory.'/../'.basename($profile->server_directory)]); self::fail('Duplicate directory accepted'); } catch (\InvalidArgumentException) { self::assertCount(1, $registry->settings()->servers); }
        foreach (['https://example.test', '127.0.0.1', '192.168.0.1', 'example.test..', 'example.test:15637', '../path'] as $badHost) {
            try { InstanceRegistry::publicHost($badHost); self::fail('Invalid public host accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertSame('sturmklingen.dedyn.io', InstanceRegistry::publicHost(' Sturmklingen.dedyn.io. '));
        if (PHP_OS_FAMILY !== 'Windows' && getenv('WSL_DISTRO_NAME')) {
            self::assertSame('/tmp/fixture', $paths->local('\\\\wsl.localhost\\'.getenv('WSL_DISTRO_NAME').'\\tmp\\fixture'));
            self::assertSame('/tmp/fixture', $paths->local('\\\\wsl$\\'.getenv('WSL_DISTRO_NAME').'\\tmp\\fixture'));
        }
    }
    public function testStoppedSaveMakesExactPrivateBackupAndRejectsOldFileRevision(): void
    {
        $this->boot(); $profile = $this->profile(); $this->registry()->register($this->owner(), $profile);
        $path = $this->configuration($profile); $before = PrivateFiles::read($path); $service = $this->service();
        $view = $service->view($this->owner(), $profile->id); $view['config']->name = 'Neuer Name';
        $saved = $service->save($this->owner(), $profile->id, $view['config'], $view['revision']);
        self::assertSame($before, PrivateFiles::read($this->registry()->dataPath($profile->id).'/backups/'.$saved['backup']));
        self::assertSame('Neuer Name', $service->view($this->owner(), $profile->id)['config']->name);
        try { $service->save($this->owner(), $profile->id, $view['config'], $view['revision']); self::fail('Stale file overwritten'); } catch (\DomainException) { self::assertSame($saved['revision'], hash_file('sha256', $path)); }
    }
    public function testMutationsRejectUnknownStateLauncherPendingJobsAndRestore(): void
    {
        $this->boot(); $profile = $this->profile(); $this->registry()->register($this->owner(), $profile);
        $path = $this->configuration($profile); $before = PrivateFiles::read($path); $config = (new EnshroudedConfiguration())->decode($before);
        foreach ([[], ['running' => false], ['running' => false, 'starting' => false, 'launcher_running' => true],
            ['running' => true, 'starting' => false, 'launcher_running' => false]] as $status) {
            try { $this->service($status)->save($this->owner(), $profile->id, $config, hash('sha256', $before)); self::fail('Unknown/running state accepted'); } catch (\DomainException) { self::assertSame($before, PrivateFiles::read($path)); }
        }
        $pending = $this->directory.'/data/world-backups/'.$profile->id; PrivateFiles::directory($pending); PrivateFiles::write($pending.'/restore-pending.json', '{}');
        try { $this->service()->save($this->owner(), $profile->id, $config, hash('sha256', $before)); self::fail('Pending restore ignored'); } catch (\DomainException) { self::assertSame($before, PrivateFiles::read($path)); }
        unlink($pending.'/restore-pending.json'); static::getContainer()->get(JobRepository::class)->insert('fixture', 'fixture', $profile->id, 'start', $this->owner());
        $this->expectException(\DomainException::class); $this->service()->save($this->owner(), $profile->id, $config, hash('sha256', $before));
    }
    public function testConfigurationSharesTheWorkerExecutionLock(): void
    {
        $this->boot(); $profile = $this->profile(); $this->registry()->register($this->owner(), $profile); $this->configuration($profile);
        $view = $this->service()->view($this->owner(), $profile->id);
        $lock = (new LockFactory(new FlockStore($this->directory.'/locks')))->createLock('execute:'.$profile->id); self::assertTrue($lock->acquire());
        try {
            $this->expectException(\DomainException::class); $this->service()->save($this->owner(), $profile->id, $view['config'], $view['revision']);
        } finally { $lock->release(); }
    }
    public function testOverlappingWorldOrConfigFilesAreRejectedBeforeBackup(): void
    {
        $this->boot(); $a = $this->profile('one'); $b = $this->profile('two');
        foreach ([$a, $b] as $profile) { $this->registry()->register($this->owner(), $profile); $this->configuration($profile); }
        $service = $this->service(); $view = $service->view($this->owner(), 'one');
        $view['config']->saveDirectory = $b->server_directory.'/savegame/subworld';
        try { $service->save($this->owner(), 'one', $view['config'], $view['revision']); self::fail('Overlapping world accepted'); } catch (\DomainException) { self::assertDirectoryDoesNotExist($this->registry()->dataPath('one').'/backups'); }
        $worlds = static::getContainer()->get(WorldBinding::class);
        $aska = $this->profile('aska', 'aska'); $config = (new AskaConfiguration())->initial(); $config->{'save id'} = 'fixed';
        $binding = $worlds->resolve($aska, $config, $this->directory.'/windows-user');
        self::assertStringEndsWith('/Aska/data/server/savegame_fixed', $binding['world']);
        self::assertStringNotContainsString('/Aska/data/savegame_fixed', $binding['world']);
    }
    public function testHttpScopesConfigurationAndDoesNotExposeSharedManagerSecrets(): void
    {
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']); $this->ledger();
        $profile = $this->profile(); $this->registry()->register($this->owner(), $profile); $this->configuration($profile);
        $store = static::getContainer()->get(AccessStore::class);
        $store->configure($this->owner(), ['enabled' => true, 'hostname' => 'fixture.test.ts.net', 'port' => 18870]);
        $store->saveUser($this->owner(), ['login' => 'fixture@example.test', 'name' => 'Family', 'role' => 'family', 'server_ids' => [$profile->id], 'enabled' => true], [$profile->id]);
        $remote = ['HTTP_HOST' => 'fixture.test.ts.net', 'INDY_LISTENER_PORT' => '18870', 'HTTP_TAILSCALE_USER_LOGIN' => 'fixture@example.test'];
        $client->request('GET', '/api/servers', server: $remote); self::assertResponseIsSuccessful();
        $catalog = json_decode($client->getResponse()->getContent(), true); self::assertSame(['id', 'label', 'game'], array_keys($catalog['servers'][0]));
        $client->request('GET', '/api/config?server=enshrouded', server: $remote); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/config'); self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/api/config?server=enshrouded'); self::assertResponseIsSuccessful();
        $config = json_decode($client->getResponse()->getContent(), true); self::assertSame($profile->id, $config['server_id']);
        $client->request('GET', '/api/session'); $token = json_decode($client->getResponse()->getContent(), true)['token'];
        // The isolated control remains restricted to its own standins, even after metadata import.
        $client->request('POST', '/api/config', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token], content: json_encode($config));
        self::assertResponseStatusCodeSame(409);
    }
    public function testFilesystemRootCannotBecomeAWorldOrEscapeOverlapChecks(): void
    {
        $this->boot(); $profile = $this->profile(); $this->registry()->register($this->owner(), $profile); $this->configuration($profile);
        $paths = new NativePaths();
        $root = PHP_OS_FAMILY === 'Windows' ? substr($this->directory, 0, 3) : '/';
        self::assertNotSame('', $paths->identity($root));
        if (PHP_OS_FAMILY !== 'Windows') { self::assertSame('/', $paths->identity($root)); }
        self::assertTrue($paths->contains($root, $this->directory));
        $worlds = static::getContainer()->get(WorldBinding::class);
        self::assertTrue($worlds->overlaps(['world' => $root, 'files' => []], ['world' => $this->directory, 'files' => []]));
        $view = $this->service()->view($this->owner(), $profile->id); $view['config']->saveDirectory = $root;
        try { $worlds->resolve($profile, $view['config'], null); self::fail('Root world accepted'); }
        catch (\DomainException) { self::assertTrue(true); }
        try { $this->service()->save($this->owner(), $profile->id, $view['config'], $view['revision']); self::fail('Root world written'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($this->registry()->dataPath($profile->id).'/backups'); }
    }
}
