<?php
declare(strict_types=1);
namespace App\Tests;

use App\Runtime\StateInstallation;
use App\Runtime\StateSnapshot;
use App\Runtime\ReleasePackage;
use App\Storage\PrivateFiles;
use App\WhatsApp\DeliveryStore;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class StateInstallationTest extends SliceTestCase
{
    private string $source;
    private int $port;
    private int $family;
    protected function setUp(): void
    {
        parent::setUp(); if (PHP_OS_FAMILY!=='Linux') { self::markTestSkipped('Erste Dateninstallation für WSL/Linux.'); }
        [$this->source,$this->port,$this->family]=LegacyStateFixture::create($this->directory.'/legacy'); static::bootKernel();
    }
    private function package(): string
    {
        $path=$this->directory.'/package'; static::getContainer()->get(StateSnapshot::class)->copy($this->source,$path,$this->port); return $path;
    }
    private function release(): array
    {
        // Build a complete application solely in our own temporary repository, never mark/import
        // the developer checkout. The real initialization subprocess consumes this readonly code.
        $repository=dirname(__DIR__,2); $source=$this->directory.'/own-code'; PrivateFiles::directory($source);
        (new Process(['git','-C',$repository,'archive','--format=zip','--output='.$this->directory.'/code.zip','HEAD']))->mustRun();
        $zip=new \ZipArchive(); $zip->open($this->directory.'/code.zip'); $zip->extractTo($source); $zip->close();
        foreach ([['init','--initial-branch=main'],['add','.'],['-c','user.name=Own Fixture','-c','user.email=fixture@example.test','commit','-m','Own fixture']] as $arguments) {
            (new Process(['git','-C',$source,...$arguments]))->mustRun();
        }
        $process=new Process(['git','-C',$source,'rev-parse','HEAD']); $process->mustRun(); $commit=trim($process->getOutput());
        (new Filesystem())->mirror($repository.'/symfony/vendor',$source.'/symfony/vendor');
        $result=(new ReleasePackage())->build($source,$this->directory.'/release',$commit); return [$result['directory'],$commit];
    }
    public function testRealPreparationPreservesKeysOwnersClaimsAndBackupsWithoutStartingAnyRuntime(): void
    {
        $package=$this->package(); [$release,$commit]=$this->release(); $php=getenv('SLICE_PHP') ?: PHP_BINARY;
        $before=hash_file('sha256',$this->source.'/data/whatsapp/deliveries.sqlite3'); $key=PrivateFiles::read($this->source.'/data/session-key');
        $result=static::getContainer()->get(StateInstallation::class)->install($package,$this->directory.'/installed',$release,$commit,$php,$php); $state=$result['directory'];
        self::assertTrue($result['prepared']); self::assertFalse($result['activated']);
        self::assertSame($key,PrivateFiles::read($state.'/data/session-key')); self::assertFileExists($state.'/slice.sqlite');
        self::assertFileDoesNotExist($state.'/runtime-lifecycle.json'); self::assertDirectoryDoesNotExist($state.'/runtime');
        self::assertDirectoryDoesNotExist($state.'/data/whatsapp/sessions'); self::assertSame('opaque own archive standin',PrivateFiles::read($state.'/data/world-backups/own-backup/world.zip'));
        $db=DeliveryStore::open($state.'/data/whatsapp/deliveries.sqlite3'); self::assertSame(['sending','sent','uncertain'],$db->query('SELECT state FROM deliveries ORDER BY state')->fetchAll(\PDO::FETCH_COLUMN)); $db=null;
        self::assertSame($before,hash_file('sha256',$this->source.'/data/whatsapp/deliveries.sqlite3'));
        self::assertTrue((new ReleasePackage())->verify($release,$commit)['verified']);
        $python=new Process([getenv('PYTHON') ?: 'python3','-c',
            'from pathlib import Path; import sys; from gameserver_manager.runtime_state import validate_prepared_state; from gameserver_manager.registry import ServerRegistry; from gameserver_manager.access import AccessService; root=Path(sys.argv[1]); validate_prepared_state(root); registry=ServerRegistry(root=root); assert registry.get("aska").profile["id"]=="aska"; assert AccessService(root,registry).principal("owner@example.test").role=="owner"', $state],dirname(__DIR__,2));
        $python->mustRun(); self::assertSame(0,$python->getExitCode());
        $settings=PrivateFiles::object(PrivateFiles::read($state.'/runtime.local.json')); self::assertSame($this->port,$settings->port); self::assertSame($this->family,$settings->family_port);
        $socket=stream_socket_server('tcp://127.0.0.1:'.$this->port); self::assertIsResource($socket); fclose($socket);
    }
    public function testStaleSourceConfigurationDataAndAlteredPackagesAreRejectedWithoutModification(): void
    {
        $package=$this->package(); $snapshot=static::getContainer()->get(StateSnapshot::class); $manifest=$snapshot->verify($package);
        $config=$this->source.'/own-install/server properties.txt'; $bytes=PrivateFiles::read($config); PrivateFiles::write($config,'changed');
        try { $snapshot->assertCurrent($manifest); self::fail('Stale game configuration accepted.'); } catch (\DomainException) { self::assertSame('changed',PrivateFiles::read($config)); }
        PrivateFiles::write($config,$bytes); PrivateFiles::write($this->source.'/data/new-file','later data');
        try { $snapshot->assertCurrent($manifest); self::fail('Stale administration accepted.'); } catch (\DomainException) { self::assertSame('later data',PrivateFiles::read($this->source.'/data/new-file')); }
        PrivateFiles::write($package.'/state/data/session-key','altered');
        $this->expectException(\DomainException::class); $snapshot->verify($package);
    }
    public function testBusyOriginalAndStalePackageNeverPublishAnInstallation(): void
    {
        $package=$this->package(); [$release,$commit]=$this->release(); $php=getenv('SLICE_PHP') ?: PHP_BINARY;
        $installation=static::getContainer()->get(StateInstallation::class); $target=$this->directory.'/rejected';
        foreach ([$this->port,$this->family] as $port) {
            $socket=stream_socket_server('tcp://127.0.0.1:'.$port);
            try { $installation->install($package,$target,$release,$commit,$php,$php); self::fail('Busy original accepted.'); }
            catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-install-*')); }
            finally { fclose($socket); }
        }
        PrivateFiles::write($this->source.'/data/new-data','changed since copy');
        try { $installation->install($package,$target,$release,$commit,$php,$php); self::fail('Stale package installed.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-install-*')); }
        self::assertSame('changed since copy',PrivateFiles::read($this->source.'/data/new-data'));
    }
    public function testPreparationFailureCleansOnlyNewFilesAndExistingTargetsStayUntouched(): void
    {
        $package=$this->package(); [$release,$commit]=$this->release(); $target=$this->directory.'/rejected';
        $installation=static::getContainer()->get(StateInstallation::class);
        $key=PrivateFiles::read($this->source.'/data/session-key'); $manifest=PrivateFiles::read($package.'/manifest.json');
        try { $installation->install($package,$target,$release,$commit,'/bin/false','/bin/false'); self::fail('Failed preparation published.'); }
        catch (\Symfony\Component\Process\Exception\ProcessFailedException) {
            self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-install-*'));
        }
        self::assertSame($key,PrivateFiles::read($this->source.'/data/session-key')); self::assertSame($manifest,PrivateFiles::read($package.'/manifest.json'));
        self::assertTrue((new ReleasePackage())->verify($release,$commit)['verified']);
        PrivateFiles::directory($target); PrivateFiles::write($target.'/sentinel','existing');
        try { $installation->install($package,$target,$release,$commit,'/bin/false','/bin/false'); self::fail('Existing target replaced.'); }
        catch (\DomainException) { self::assertSame('existing',PrivateFiles::read($target.'/sentinel')); }
    }
    public function testPackageVerificationAlsoRejectsUnlistedSqliteSharedMemoryFiles(): void
    {
        $package=$this->package(); PrivateFiles::write($package.'/state/data/access.sqlite3-shm','unlisted');
        $this->expectException(\DomainException::class); static::getContainer()->get(StateSnapshot::class)->verify($package);
    }
    protected function tearDown(): void
    {
        // Readonly code belongs exclusively to this fixture; make its own folders removable.
        if (isset($this->directory) && is_dir($this->directory)) {
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) { if ($entry->isDir() && !$entry->isLink()) { chmod($entry->getPathname(),0700); } }
        }
        parent::tearDown();
    }
}
