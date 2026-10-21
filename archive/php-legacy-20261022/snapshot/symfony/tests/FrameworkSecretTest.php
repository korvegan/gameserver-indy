<?php
declare(strict_types=1);
namespace App\Tests;

use App\Kernel;
use App\Runtime\FrameworkSecret;
use App\Storage\PrivateFiles;

final class FrameworkSecretTest extends SliceTestCase
{
    public function testFrameworkSecretUsesExistingKeyAcrossReleasesAndKeepsLogsOutsideCode(): void
    {
        PrivateFiles::directory($this->directory.'/data');
        PrivateFiles::write($this->directory.'/.indy-managed-state','{"format":"gameserver-indy-state","version":1,"access_layout":"shared-data"}');
        $key=str_repeat('A',43); PrivateFiles::write($this->directory.'/data/session-key',$key);
        $processor=new FrameworkSecret(); $resolve=fn(string $name): string=>$this->directory;
        $secret=$processor->getEnv('indy_secret','SLICE_STATE_DIR',$resolve);
        self::assertSame(hash_hmac('sha256','gameserver-indy/symfony/framework/v1',$key),$secret);
        self::assertNotSame(hash_hmac('sha256','local-owner',$key),$secret);
        self::assertSame($secret,$processor->getEnv('indy_secret','SLICE_STATE_DIR',$resolve));
        self::assertSame($key,PrivateFiles::read($this->directory.'/data/session-key'));
        self::assertSame($this->directory.'/runtime/logs',(new Kernel('prod',false))->getLogDir());
    }
    public function testManagedMissingAndInvalidKeysFailWithoutReplacement(): void
    {
        PrivateFiles::directory($this->directory.'/data'); PrivateFiles::write($this->directory.'/.indy-managed-state','{}');
        $processor=new FrameworkSecret(); $resolve=fn(string $name): string=>$this->directory;
        try { $processor->getEnv('indy_secret','SLICE_STATE_DIR',$resolve); self::fail('Missing key accepted.'); }
        catch (\DomainException) { self::assertFileDoesNotExist($this->directory.'/data/session-key'); }
        PrivateFiles::write($this->directory.'/data/session-key','invalid');
        try { $processor->getEnv('indy_secret','SLICE_STATE_DIR',$resolve); self::fail('Invalid key accepted.'); }
        catch (\DomainException) { self::assertSame('invalid',PrivateFiles::read($this->directory.'/data/session-key')); }
    }
    public function testRealContainerResolvesItsFrameworkSecretWithoutExposingItInCompiledConfiguration(): void
    {
        $key=str_repeat('B',43); PrivateFiles::write($this->directory.'/session-key',$key);
        static::bootKernel();
        self::assertSame(hash_hmac('sha256','gameserver-indy/symfony/framework/v1',$key),static::getContainer()->getParameter('kernel.secret'));
    }
}
