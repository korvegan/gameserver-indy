<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\Principal;
use App\Backup\BackupFiles;
use App\Backup\WorldBackups;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WorldBackupsTest extends SliceTestCase
{
    private MockClock $clock;
    private string $world;
    private function owner(): Principal { return new Principal('local-owner', 'owner'); }
    private function nativeState(): array
    {
        return ['known' => true, 'running' => false, 'starting' => false, 'launcher_running' => false,
            'processes' => [], 'pid' => null, 'ready' => null, 'user_profile' => $this->directory.'/native-user'];
    }
    private function fixture(?ProcessGateway $gateway = null, bool $http = false): ?\Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $this->clock = new MockClock('2026-10-01T12:00:00+00:00'); Clock::set($this->clock);
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $client = $http ? static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']) : null;
        if ($client === null) { static::bootKernel(); } else { $client->disableReboot(); }
        $this->ledger(); $container = static::getContainer();
        if ($gateway === null) { $gateway = $this->createStub(ProcessGateway::class); $gateway->method('call')->willReturn($this->nativeState()); }
        $container->set(ProcessGateway::class, $gateway);
        $install = $this->directory.'/own-install'; PrivateFiles::directory($install);
        PrivateFiles::write($install.'/enshrouded_server.exe', 'own non-executable placeholder');
        $container->get(InstanceRegistry::class)->register($this->owner(), (object) ['id' => 'fixture', 'game' => 'enshrouded', 'label' => 'Synthetic world', 'server_directory' => $install]);
        $config = (new EnshroudedConfiguration())->initial(); $config->userGroups[0]->password = 'synthetic-private-world-password';
        PrivateFiles::write($install.'/enshrouded_server.json', PrivateFiles::json($config));
        $this->world = $install.'/savegame'; PrivateFiles::directory($this->world.'/backup'); PrivateFiles::directory($this->world.'/empty');
        PrivateFiles::write($this->world.'/world0.ksd', "world one\0\xff"); PrivateFiles::write($this->world.'/backup/prior.ksd', 'prior');
        $container->get(RuntimeLifecycle::class)->open(); $container->get(ProcessObservation::class)->record('fixture', $this->nativeState());
        return $client;
    }
    private function backups(): WorldBackups { return static::getContainer()->get(WorldBackups::class); }
    private function execute(string $operation, ?\stdClass $payload = null): array
    {
        return $this->backups()->execute($this->owner(), 'fixture', $operation, $payload);
    }
    private function create(): string { return $this->execute('backup')['backup_id']; }
    private function file(string $id, string $extension): string { return $this->directory.'/data/world-backups/fixture/'.$id.'.'.$extension; }
    private function record(string $id): \stdClass { return PrivateFiles::object(PrivateFiles::read($this->file($id, 'json'))); }
    private function restore(string $id): array
    {
        $preview = $this->backups()->preview($this->owner(), 'fixture', $id);
        return $this->execute('backup_restore', (object) ['backup_id' => $id, 'token' => $preview['token'], 'confirmation' => $preview['confirmation']]);
    }
    protected function tearDown(): void { Clock::set(new NativeClock()); parent::tearDown(); }
    public function testCompleteWorldAndExactConfigRoundTripWithoutResettingAccessOrChronicle(): void
    {
        $this->fixture(); $config = $this->directory.'/own-install/enshrouded_server.json'; $bytes = PrivateFiles::read($config);
        PrivateFiles::write($this->directory.'/unrelated-chronicle', 'untouched');
        $accessHash = hash_file('sha256', $this->directory.'/access.sqlite3');
        $id = $this->create(); $record = $this->record($id); $zip = new \ZipArchive(); self::assertTrue($zip->open($this->file($id, 'zip'), \ZipArchive::RDONLY));
        try {
            self::assertSame($bytes, $zip->getFromName('config/server')); self::assertSame("world one\0\xff", $zip->getFromName('world/world0.ksd'));
            self::assertSame('prior', $zip->getFromName('world/backup/prior.ksd')); self::assertContains('world/empty', $record->manifest->directories);
        } finally { $zip->close(); }
        PrivateFiles::write($this->world.'/world0.ksd', 'later'); PrivateFiles::write($this->world.'/new-file', 'later');
        $changed = PrivateFiles::object($bytes); $changed->name = 'later name'; PrivateFiles::write($config, PrivateFiles::json($changed));
        $result = $this->restore($id); self::assertSame("world one\0\xff", file_get_contents($this->world.'/world0.ksd'));
        self::assertFileDoesNotExist($this->world.'/new-file'); self::assertSame($bytes, PrivateFiles::read($config));
        self::assertTrue($this->record($result['before_backup_id'])->pinned); self::assertSame('before_restore', $this->record($result['before_backup_id'])->reason);
        self::assertFalse($this->backups()->view($this->owner(), 'fixture')['recovery_required']);
        self::assertSame($accessHash, hash_file('sha256', $this->directory.'/access.sqlite3')); self::assertSame('untouched', PrivateFiles::read($this->directory.'/unrelated-chronicle'));
        $view = $this->backups()->view($this->owner(), 'fixture'); self::assertSame(72, $view['retention_hours']); self::assertFalse($view['automatic_after_stop']);
        self::assertStringNotContainsString('synthetic-private-world-password', json_encode($view));
        if (PHP_OS_FAMILY !== 'Windows') { self::assertSame(0600, fileperms($this->file($id, 'zip')) & 0777); }
    }
    public function testRetentionNeedsNewVerifiedSuccessAndPreservesPins(): void
    {
        $this->fixture(); $old = $this->create(); $pinned = $this->create();
        $this->execute('backup_pin', (object) ['backup_id' => $pinned, 'pinned' => true]);
        $this->clock->modify('+73 hours');
        $config = $this->directory.'/own-install/enshrouded_server.json'; $bytes = PrivateFiles::read($config); PrivateFiles::write($config, '{}');
        try { $this->create(); self::fail('Invalid source published'); } catch (\InvalidArgumentException) { self::assertFileExists($this->file($old, 'zip')); }
        PrivateFiles::write($config, $bytes); $new = $this->create();
        self::assertFileDoesNotExist($this->file($old, 'zip')); self::assertFileDoesNotExist($this->file($old, 'json'));
        self::assertFileExists($this->file($pinned, 'zip')); self::assertFileExists($this->file($new, 'zip'));
    }
    public function testCorruptedArchiveLosesVerificationWithoutChangingWorld(): void
    {
        $this->fixture(); $id = $this->create(); file_put_contents($this->file($id, 'zip'), 'corrupt', FILE_APPEND);
        try { $this->execute('backup_verify', (object) ['backup_id' => $id]); self::fail('Corrupt archive accepted'); }
        catch (\DomainException) { self::assertNull($this->record($id)->verified_at); self::assertSame("world one\0\xff", file_get_contents($this->world.'/world0.ksd')); }
    }
    public function testUnsafeArchiveMemberIsRejectedEvenWithUpdatedWholeArchiveHash(): void
    {
        $this->fixture(); $id = $this->create(); $path = $this->file($id, 'zip'); $zip = new \ZipArchive();
        self::assertTrue($zip->open($path)); $zip->addFromString('../escaped', 'must never extract'); self::assertTrue($zip->close());
        $record = $this->record($id); $record->sha256 = hash_file('sha256', $path); PrivateFiles::write($this->file($id, 'json'), PrivateFiles::json($record));
        try { $this->restore($id); self::fail('Unsafe archive accepted'); }
        catch (\DomainException) {
            self::assertSame("world one\0\xff", file_get_contents($this->world.'/world0.ksd'));
            self::assertFileDoesNotExist($this->directory.'/escaped'); self::assertNull($this->record($id)->verified_at);
        }
    }
    public function testValidArchiveCannotRedirectRestoredConfigToAnotherWorld(): void
    {
        $this->fixture(); $id = $this->create(); $record = $this->record($id); $path = $this->file($id, 'zip');
        $other = $this->directory.'/unrelated-world'; PrivateFiles::directory($other); PrivateFiles::write($other.'/world0.ksd', 'unrelated');
        $config = PrivateFiles::object(PrivateFiles::read($this->directory.'/own-install/enshrouded_server.json')); $config->saveDirectory = $other;
        $bytes = PrivateFiles::json($config);
        foreach ($record->manifest->files as $file) { if ($file->name === 'config/server') { $file->size = strlen($bytes); $file->sha256 = hash('sha256', $bytes); } }
        $zip = new \ZipArchive(); self::assertTrue($zip->open($path)); $zip->addFromString('config/server', $bytes);
        $zip->addFromString('manifest.json', PrivateFiles::json($record->manifest)); self::assertTrue($zip->close());
        $record->sha256 = hash_file('sha256', $path); PrivateFiles::write($this->file($id, 'json'), PrivateFiles::json($record));
        PrivateFiles::write($this->world.'/world0.ksd', 'current original world');
        try { $this->restore($id); self::fail('Archive redirected world'); }
        catch (\DomainException) {
            self::assertSame('current original world', file_get_contents($this->world.'/world0.ksd')); self::assertSame('unrelated', PrivateFiles::read($other.'/world0.ksd'));
            self::assertFalse($this->backups()->view($this->owner(), 'fixture')['recovery_required']);
        }
    }
    public function testPreviewRevisionAndExplicitConfirmationProtectCurrentTarget(): void
    {
        $this->fixture(); $id = $this->create(); $preview = $this->backups()->preview($this->owner(), 'fixture', $id);
        $config = $this->directory.'/own-install/enshrouded_server.json'; $value = PrivateFiles::object(PrivateFiles::read($config));
        $value->name = 'new configuration'; PrivateFiles::write($config, PrivateFiles::json($value));
        try { $this->execute('backup_restore', (object) ['backup_id' => $id, 'token' => $preview['token'], 'confirmation' => 'WIEDERHERSTELLEN']); self::fail('Stale preview accepted'); }
        catch (\DomainException) { self::assertSame('new configuration', PrivateFiles::object(PrivateFiles::read($config))->name); }
        $this->expectException(\InvalidArgumentException::class);
        $this->execute('backup_restore', (object) ['backup_id' => $id, 'token' => $preview['token'], 'confirmation' => 'yes']);
    }
    public function testPersistentRecoveryPreservesPinAndReturnsPreviousExactBytes(): void
    {
        $this->fixture(); $before = $this->create(); $this->execute('backup_pin', (object) ['backup_id' => $before, 'pinned' => true]);
        $marker = $this->directory.'/data/world-backups/fixture/restore-pending.json';
        PrivateFiles::write($marker, PrivateFiles::json((object) ['before' => $before, 'binding' => $this->record($before)->manifest->binding]));
        PrivateFiles::write($this->world.'/world0.ksd', 'interrupted replacement');
        static::getContainer()->get(RuntimeLifecycle::class)->close(); static::getContainer()->get(RuntimeLifecycle::class)->open();
        try { $this->execute('backup_pin', (object) ['backup_id' => $before, 'pinned' => false]); self::fail('Recovery pin removed'); }
        catch (\DomainException) { self::assertTrue($this->record($before)->pinned); }
        try { $this->create(); self::fail('Pending guard bypassed'); }
        catch (\DomainException) { self::assertFileExists($marker); }
        $preview = $this->backups()->recoveryPreview($this->owner(), 'fixture');
        $this->execute('backup_recover', (object) ['backup_id' => $before, 'token' => $preview['token'], 'confirmation' => $preview['confirmation']]);
        self::assertSame("world one\0\xff", file_get_contents($this->world.'/world0.ksd')); self::assertFileDoesNotExist($marker);
    }
    public function testUnknownRunningOrLauncherStateNeverPublishesBackup(): void
    {
        $gateway = $this->createStub(ProcessGateway::class); $gateway->method('call')->willReturn([...$this->nativeState(), 'launcher_running' => true]);
        $this->fixture($gateway);
        try { $this->create(); self::fail('Running starter accepted'); } catch (\DomainException) { self::assertSame([], $this->backups()->view($this->owner(), 'fixture')['backups']); }
    }
    public function testChangedSourceCannotPublish(): void
    {
        $calls = 0; $gateway = $this->createStub(ProcessGateway::class);
        $gateway->method('call')->willReturnCallback(function () use (&$calls): array {
            $calls++; if ($calls === 2) { PrivateFiles::write($this->world.'/world0.ksd', "world two\0\xff"); }
            return $this->nativeState();
        });
        $this->fixture($gateway);
        try { $this->create(); self::fail('Unstable source accepted'); }
        catch (\DomainException) { self::assertSame([], $this->backups()->view($this->owner(), 'fixture')['backups']); }
        self::assertSame("world two\0\xff", file_get_contents($this->world.'/world0.ksd'));
    }
    public function testShutdownAfterReplacementLeavesDurableRecoveryAndNeverReplays(): void
    {
        $calls = 0; $interruptAt = null; $gateway = $this->createStub(ProcessGateway::class);
        $gateway->method('call')->willReturnCallback(function () use (&$calls, &$interruptAt): array {
            $calls++;
            if ($calls === $interruptAt) { static::getContainer()->get(RuntimeLifecycle::class)->close(); }
            return $this->nativeState();
        });
        $this->fixture($gateway); $original = $this->create(); PrivateFiles::write($this->world.'/world0.ksd', 'before interrupted restore');
        $calls = 0; $interruptAt = 7;
        $preview = $this->backups()->preview($this->owner(), 'fixture', $original); $container = static::getContainer();
        $job = $container->get(JobService::class)->submit('fixture', 'backup_restore', bin2hex(random_bytes(16)), $this->owner(),
            (object) ['backup_id' => $original, 'token' => $preview['token'], 'confirmation' => 'WIEDERHERSTELLEN']);
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($job['id'])['state']);
        self::assertSame("world one\0\xff", file_get_contents($this->world.'/world0.ksd'));
        $marker = $this->directory.'/data/world-backups/fixture/restore-pending.json'; self::assertFileExists($marker);
        $before = PrivateFiles::object(PrivateFiles::read($marker))->before; self::assertTrue($this->record($before)->pinned);
        static::getContainer()->get(RuntimeLifecycle::class)->open(); $interruptAt = null;
        try { static::getContainer()->get(\App\Runtime\ManagedServerControl::class)->execute('fixture', 'start'); self::fail('Pending restore allowed start'); }
        catch (\DomainException) { self::assertFileExists($marker); }
        $preview = $this->backups()->recoveryPreview($this->owner(), 'fixture');
        $this->execute('backup_recover', (object) ['backup_id' => $before, 'token' => $preview['token'], 'confirmation' => 'WIEDERHERSTELLEN']);
        self::assertSame('before interrupted restore', file_get_contents($this->world.'/world0.ksd')); self::assertFileDoesNotExist($marker);
    }
    private function restartWithAutomaticBackup(bool $fail): array
    {
        $running = true; $actions = []; $gateway = $this->createStub(ProcessGateway::class);
        $gateway->method('call')->willReturnCallback(function ($id, $action) use (&$running, &$actions): array {
            $actions[] = $action;
            if ($action === 'Stop') { $running = false; } elseif ($action === 'Start') { $running = true; }
            return [...$this->nativeState(), 'running' => $running, 'pid' => $running ? 123 : null,
                'processes' => $running ? [['pid' => 123, 'started' => '2026-10-16T00:00:00Z']] : []];
        });
        $this->fixture($gateway); $this->execute('backup_settings', (object) ['automatic_after_stop' => true]);
        if ($fail) { unlink($this->world.'/world0.ksd'); unlink($this->world.'/backup/prior.ksd'); }
        $container = static::getContainer(); $service = $container->get(JobService::class);
        $restart = $service->submit('fixture', 'restart', bin2hex(random_bytes(16)));
        $follower = $service->submit('fixture', 'start', bin2hex(random_bytes(16)));
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($restart['id']));
        return [$actions, $container->get(JobRepository::class)->get($restart['id']), $container->get(JobRepository::class)->get($follower['id'])];
    }
    public function testOptionalBackupRunsBetweenRegularStopAndStartWithinSameClaim(): void
    {
        [$calls, $restart, $follower] = $this->restartWithAutomaticBackup(false);
        self::assertSame('succeeded', $restart['state']); self::assertSame('succeeded', $follower['state']);
        self::assertSame(1, count(array_filter($calls, fn ($call) => $call === 'Start')));
        $records = $this->backups()->view($this->owner(), 'fixture')['backups']; self::assertCount(1, $records); self::assertSame('after_stop', $records[0]['reason']);
    }
    public function testFailedAfterStopBackupLeavesStoppedAndInterruptsQueuedStart(): void
    {
        [$calls, $restart, $follower] = $this->restartWithAutomaticBackup(true);
        self::assertSame('interrupted', $restart['state']); self::assertSame('interrupted', $follower['state']);
        self::assertNotContains('Start', $calls); self::assertContains('Stop', $calls);
        self::assertSame([], $this->backups()->view($this->owner(), 'fixture')['backups']);
    }
    public function testExclusiveJobsAndLimitedRolesCannotMutateBackupState(): void
    {
        $this->fixture(); $container = static::getContainer(); $jobs = $container->get(JobService::class);
        foreach ([new Principal('limited', 'family', ['fixture']), new Principal('reader', 'viewer')] as $principal) {
            foreach (['backup', 'backup_verify', 'backup_pin', 'backup_settings', 'backup_restore', 'backup_recover'] as $operation) {
                try { $jobs->submit('fixture', $operation, bin2hex(random_bytes(16)), $principal); self::fail('Role crossed backup boundary'); }
                catch (AccessDeniedException) { self::assertTrue(true); }
            }
            try { $this->backups()->view($principal, 'fixture'); self::fail('Private archives exposed'); } catch (AccessDeniedException) { self::assertTrue(true); }
        }
        $job = $jobs->submit('fixture', 'backup', bin2hex(random_bytes(16)));
        try { $jobs->submit('fixture', 'start', bin2hex(random_bytes(16))); self::fail('Exclusive target crossed'); }
        catch (\DomainException) { self::assertSame(1, $container->get(JobRepository::class)->pendingCount('fixture')); }
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('succeeded', $container->get(JobRepository::class)->get($job['id'])['state']);
    }
    public function testAskaUsesServerWorldAndExactExternalConfigAndPreset(): void
    {
        $this->fixture(); $container = static::getContainer(); $registry = $container->get(InstanceRegistry::class);
        $install = $this->directory.'/aska-install'; $external = $this->directory.'/external'; PrivateFiles::directory($install); PrivateFiles::directory($external);
        $preset = $external.'/preset.json'; PrivateFiles::write($preset, "{\"synthetic\":true}\r\n");
        $configPath = $external.'/server properties.txt'; $bytes = "\xef\xbb\xbf# keep\r\nsave id=fixture_123\r\nserver name=Synthetic\r\ndisplay name=Synthetic\r\nsteam game port=27015\r\nsteam query port=27016\r\npassword=synthetic-private-token\r\ncustom game preset path=".$preset."\r\n";
        PrivateFiles::write($configPath, $bytes);
        $registry->register($this->owner(), (object) ['id' => 'aska-own', 'game' => 'aska', 'label' => 'Own Aska', 'server_directory' => $install, 'config_path' => $configPath]);
        $base = $this->directory.'/native-user/AppData/LocalLow/Sand Sailor Studio/Aska/data';
        PrivateFiles::directory($base.'/server/savegame_fixture_123'); PrivateFiles::directory($base.'/savegame_fixture_123');
        PrivateFiles::write($base.'/server/savegame_fixture_123/worldgen', 'actual world'); PrivateFiles::write($base.'/savegame_fixture_123/worldgen', 'decoy');
        $container->get(ProcessObservation::class)->record('aska-own', $this->nativeState());
        $id = $this->backups()->execute($this->owner(), 'aska-own', 'backup')['backup_id'];
        PrivateFiles::write($base.'/server/savegame_fixture_123/worldgen', 'later');
        $preview = $this->backups()->preview($this->owner(), 'aska-own', $id);
        $this->backups()->execute($this->owner(), 'aska-own', 'backup_restore', (object) ['backup_id' => $id, 'token' => $preview['token'], 'confirmation' => 'WIEDERHERSTELLEN']);
        self::assertSame('actual world', PrivateFiles::read($base.'/server/savegame_fixture_123/worldgen')); self::assertSame('decoy', PrivateFiles::read($base.'/savegame_fixture_123/worldgen'));
        self::assertSame($bytes, PrivateFiles::read($configPath)); self::assertSame("{\"synthetic\":true}\r\n", PrivateFiles::read($preset));
    }
    public function testHttpAcceptsBackupWithoutNativeIOAndRejectsArbitraryPayload(): void
    {
        $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::never())->method('call'); $client = $this->fixture($gateway, true);
        $client->request('GET', '/api/session'); $session = json_decode($client->getResponse()->getContent(), true);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $session['token']];
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode(['server_id' => 'fixture', 'operation' => 'backup', 'request_id' => bin2hex(random_bytes(16))]));
        self::assertResponseStatusCodeSame(202); self::assertStringNotContainsString('synthetic-private-world-password', $client->getResponse()->getContent());
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode(['server_id' => 'fixture', 'operation' => 'backup_restore', 'request_id' => bin2hex(random_bytes(16)),
            'payload' => ['backup_id' => str_repeat('a', 32), 'token' => str_repeat('b', 64), 'confirmation' => 'WIEDERHERSTELLEN', 'path' => '/arbitrary']]));
        self::assertResponseStatusCodeSame(400);
    }
    public function testLinksHardlinksScanFailuresAndSharedWorldsFailClosed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('Native NTFS link regression is separate.'); }
        $this->fixture(); $link = $this->world.'/linked'; symlink($this->world.'/world0.ksd', $link);
        try { $this->create(); self::fail('Symlink followed'); } catch (\DomainException) { self::assertTrue(is_link($link)); } finally { unlink($link); }
        link($this->world.'/world0.ksd', $link);
        try { $this->create(); self::fail('Hardlink followed'); } catch (\DomainException) { self::assertFileExists($link); } finally { unlink($link); }
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            chmod($this->world.'/backup', 0000);
            try { $this->create(); self::fail('Unreadable scan skipped'); } catch (\DomainException) { self::assertTrue(true); } finally { chmod($this->world.'/backup', 0700); }
        }
        $registry = static::getContainer()->get(InstanceRegistry::class); $second = $this->directory.'/second'; PrivateFiles::directory($second);
        $registry->register($this->owner(), (object) ['id' => 'second', 'game' => 'enshrouded', 'label' => 'Other', 'server_directory' => $second]);
        $config = (new EnshroudedConfiguration())->initial(); $config->saveDirectory = $this->world; PrivateFiles::write($second.'/enshrouded_server.json', PrivateFiles::json($config));
        $this->expectException(\DomainException::class); $this->create();
    }
    public function testArchiveMemberNamesRejectTraversalAndWindowsAliases(): void
    {
        foreach (['../a', '/a', 'world/a:b', 'world\\a', 'world/con', 'world/NUL.txt', 'world/a.', 'world/a ', 'world/./a', 'world//a'] as $name) {
            try { BackupFiles::member($name); self::fail('Unsafe name accepted'); } catch (\DomainException) { self::assertTrue(true); }
        }
    }
}
