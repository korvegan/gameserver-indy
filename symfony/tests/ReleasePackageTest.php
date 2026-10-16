<?php
declare(strict_types=1);
namespace App\Tests;

use App\Runtime\ReleasePackage;
use App\Storage\PrivateFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleasePackageTest extends TestCase
{
    private string $directory;
    private string $source;
    private string $commit;
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY!=='Linux') { self::markTestSkipped('Erste Releasepakete für WSL/Linux.'); }
        $this->directory=sys_get_temp_dir().'/indy-release-test-'.bin2hex(random_bytes(8));
        $this->source=$this->directory.'/own source'; PrivateFiles::directory($this->source);
        $package=['name'=>'own/package','version'=>'1.2.3','source'=>['reference'=>'own-ref'],'dist'=>['reference'=>'own-ref']];
        $files=['symfony/bin/indy'=>'<?php // own entry', 'web/app.js'=>'// own browser', 'profiles/enshrouded.json'=>'{}',
            '.gitignore'=>"symfony/vendor/\nmanager.local.json\ndata/\n",
            'symfony/composer.lock'=>json_encode(['packages'=>[$package],'packages-dev'=>[]])];
        foreach ($files as $path=>$bytes) { PrivateFiles::directory(dirname($this->source.'/'.$path)); PrivateFiles::write($this->source.'/'.$path,$bytes); }
        $this->git(['init','--initial-branch=main']); $this->git(['add','.']); $this->commit=$this->commit();
        foreach (['autoload.php','composer/installed.php','composer/autoload_real.php','own/package/code.php'] as $path) {
            PrivateFiles::directory(dirname($this->source.'/symfony/vendor/'.$path)); PrivateFiles::write($this->source.'/symfony/vendor/'.$path,'<?php // own library');
        }
        PrivateFiles::write($this->source.'/symfony/vendor/composer/installed.json',json_encode(['packages'=>[$package]]));
        PrivateFiles::write($this->source.'/manager.local.json','own private configuration must stay here');
        PrivateFiles::directory($this->source.'/data'); PrivateFiles::write($this->source.'/data/session-key','own private standin');
    }
    private function git(array $arguments): string
    {
        $p=new Process(['git','-C',$this->source,...$arguments]); $p->mustRun(); return trim($p->getOutput());
    }
    private function commit(): string
    {
        $this->git(['-c','user.name=Own Fixture','-c','user.email=fixture@example.test','commit','-m','Own fixture']);
        return $this->git(['rev-parse','HEAD']);
    }
    public function testCommitBoundReleaseContainsOnlyTrackedCodeAndMatchingLibrariesWithoutActivation(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/new release';
        $result=$packages->build($this->source,$target,$this->commit); $code=$result['directory'];
        self::assertTrue($packages->verify($code,$this->commit)['verified']);
        self::assertSame('<?php // own library',PrivateFiles::read($code.'/symfony/vendor/own/package/code.php'));
        self::assertFileDoesNotExist($code.'/manager.local.json'); self::assertDirectoryDoesNotExist($code.'/data');
        self::assertDirectoryDoesNotExist($code.'/.git'); self::assertFileDoesNotExist($code.'/.indy-managed-state');
        self::assertSame('own private standin',PrivateFiles::read($this->source.'/data/session-key'));
        self::assertSame(0,fileperms($code.'/symfony/bin/indy')&0222);
        try { $packages->build($this->source,$target,$this->commit); self::fail('Existing release overwritten.'); }
        catch (\DomainException) { self::assertTrue($packages->verify($code,$this->commit)['verified']); }
        chmod($code.'/web',0700); PrivateFiles::write($code.'/web/app.js','changed');
        $this->expectException(\DomainException::class); $packages->verify($code,$this->commit);
    }
    public function testDirtyWrongCommitAndOverlappingTargetsFailWithoutCreatingAnOutput(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/rejected';
        foreach ([[$target,str_repeat('a',40)],[$this->source.'/release',$this->commit]] as [$path,$commit]) {
            try { $packages->build($this->source,$path,$commit); self::fail('Wrong commit or overlap accepted.'); }
            catch (\DomainException) { self::assertDirectoryDoesNotExist($path); }
        }
        PrivateFiles::write($this->source.'/web/app.js','dirty');
        try { $packages->build($this->source,$target,$this->commit); self::fail('Dirty checkout accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); }
    }
    public function testMissingOrMismatchingDependenciesRejectWithoutOutput(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/rejected';
        $path=$this->source.'/symfony/vendor/composer/installed.json';
        PrivateFiles::write($path,'{"packages":[]}');
        try { $packages->build($this->source,$target,$this->commit); self::fail('Wrong dependencies accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); }
        unlink($this->source.'/symfony/vendor/autoload.php');
        $this->expectException(\DomainException::class); $packages->build($this->source,$target,$this->commit);
    }
    public function testTrackedSymlinkAndTrackedPrivateDataRemoveOnlyTheNewStagingDirectory(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/rejected';
        symlink($this->source.'/manager.local.json',$this->source.'/unsafe'); $this->git(['add','unsafe']); $commit=$this->commit();
        try { $packages->build($this->source,$target,$commit); self::fail('Git link accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-build-*')); }
        $this->git(['rm','unsafe']); $this->git(['add','-f','manager.local.json']); $commit=$this->commit();
        try { $packages->build($this->source,$target,$commit); self::fail('Private tracked configuration accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-build-*')); }
        self::assertSame('own private standin',PrivateFiles::read($this->source.'/data/session-key'));
    }
    public function testLinkedVendorAndUnlistedReleaseFilesAreRejected(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/rejected';
        symlink($this->source.'/manager.local.json',$this->source.'/symfony/vendor/unsafe');
        try { $packages->build($this->source,$target,$this->commit); self::fail('Vendor link accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); }
        unlink($this->source.'/symfony/vendor/unsafe');
        $code=$packages->build($this->source,$target,$this->commit)['directory']; chmod($code,0700); PrivateFiles::write($code.'/extra.php','unexpected');
        $this->expectException(\DomainException::class); $packages->verify($code,$this->commit);
    }
    public function testLocalGitExportAttributesCannotRemoveOrSubstituteReviewedFiles(): void
    {
        $packages=new ReleasePackage(); $target=$this->directory.'/rejected';
        PrivateFiles::write($this->source.'/web/critical.js','// reviewed $Format:%H$'); $this->git(['add','web/critical.js']); $commit=$this->commit();
        foreach (['export-ignore','export-subst'] as $attribute) {
            PrivateFiles::write($this->source.'/.git/info/attributes','web/critical.js '.$attribute."\n");
            self::assertSame('',$this->git(['status','--porcelain','--untracked-files=no']));
            try { $packages->build($this->source,$target,$commit); self::fail('Local Git archive alteration accepted.'); }
            catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame([],glob($this->directory.'/.indy-build-*')); }
        }
    }
    protected function tearDown(): void { if (isset($this->directory)) { $this->remove($this->directory); } }
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path,0700); foreach (scandir($path) as $name) { if ($name!=='.' && $name!=='..') { $this->remove($path.'/'.$name); } } rmdir($path);
        } elseif (file_exists($path) || is_link($path)) { chmod($path,0600); unlink($path); }
    }
}
