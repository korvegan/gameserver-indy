<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Instance\NativePaths;
use App\Runtime\RuntimeSettings;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingsTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/indy-runtime-test-'.bin2hex(random_bytes(8)); mkdir($this->directory,0700);
        PrivateFiles::write($this->directory.'/.indy-managed-state',PrivateFiles::json((object) ['format'=>'gameserver-indy-state','version'=>1,'access_layout'=>'shared-data']));
        mkdir($this->directory.'/data',0700);
    }
    protected function tearDown(): void
    {
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($this->directory);
    }
    private function runtime(): SliceRuntime { return new SliceRuntime($this->directory,'managed','owner'); }
    public function testSharedAccessLayoutKeepsOriginalKeyAndGrantStorageWithoutCreatingAnAlternativeKey(): void
    {
        $runtime=$this->runtime(); $runtime->initialize();
        PrivateFiles::write($this->directory.'/data/session-key',str_repeat('a',43));
        $access=new AccessStore($runtime); $access->initialize();
        $keys=new SessionKeys($runtime); $actor=new Principal('local-owner','owner');
        self::assertSame($this->directory.'/data/access.sqlite3',$access->databasePath());
        self::assertSame(hash_hmac('sha256','local-owner',str_repeat('a',43)),$keys->token($actor,'local-owner'));
        self::assertFileDoesNotExist($this->directory.'/session-key'); self::assertFileDoesNotExist($this->directory.'/access.sqlite3');
        self::assertFileDoesNotExist($this->directory.'/.indy-symfony-slice');
    }
    public function testInvalidProductionMarkerCannotFallBackToTheIsolatedMarker(): void
    {
        PrivateFiles::write($this->directory.'/.indy-symfony-slice','isolated-slice-v1');
        PrivateFiles::write($this->directory.'/.indy-managed-state','{"format":"gameserver-indy-state","version":2}');
        $this->expectException(\RuntimeException::class); $this->runtime()->initialize();
    }
    public function testRuntimeToolsAndBoundedListenerThreadValuesComeOnlyFromPrivateLocalConfiguration(): void
    {
        PrivateFiles::write($this->directory.'/runtime.local.json',PrivateFiles::json((object) ['php'=>PHP_BINARY,'frankenphp'=>PHP_BINARY,'port'=>18876,'family_port'=>18877,'threads'=>6]));
        $settings=new RuntimeSettings($this->runtime(),new NativePaths());
        self::assertSame(6,$settings->read()['threads']); self::assertSame(18876,$settings->read()['port']);
        PrivateFiles::write($this->directory.'/runtime.local.json',PrivateFiles::json((object) ['php'=>PHP_BINARY,'frankenphp'=>PHP_BINARY,'port'=>18876,'family_port'=>18876]));
        $this->expectException(\DomainException::class); $settings->read();
    }
}
