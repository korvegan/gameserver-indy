<?php
declare(strict_types=1);
namespace App\Tests;

use App\Backup\BackupFiles;
use App\Instance\NativePaths;
use App\Runtime\NativePowerShell;

final class NativeBackupPathsTest extends SliceTestCase
{
    public function testNtfsPlainFilesJunctionAndHardlinkUsingOnlyOwnMarkedTemporaryTree(): void
    {
        if (getenv('SLICE_WINDOWS_TEST') !== '1') { self::markTestSkipped('Native fixture is explicitly opt-in.'); }
        static::bootKernel(); $container = static::getContainer();
        $powershell = $container->get(NativePowerShell::class); $paths = $container->get(NativePaths::class); $files = $container->get(BackupFiles::class);
        $script = dirname(__DIR__).'/tools/prepare-backup-fixture.ps1';
        $fixture = $powershell->run($script, []); self::assertTrue($fixture->isSuccessful(), $fixture->getErrorOutput());
        $fixture = json_decode($fixture->getOutput(), true, flags: JSON_THROW_ON_ERROR); $root = $fixture['root'];
        try {
            $world = $paths->local($fixture['world']); $config = $paths->local($fixture['config']);
            $inventory = $files->inventory($world, ['config/server' => $config]); self::assertCount(2, $inventory['entries']);
            foreach (['Junction', 'Hardlink'] as $mode) {
                $created = $powershell->run($script, ['-Mode', $mode, '-Root', $root]); self::assertTrue($created->isSuccessful(), $created->getErrorOutput());
                try { $files->inventory($world, ['config/server' => $config]); self::fail('Native '.$mode.' accepted'); }
                catch (\DomainException) { self::assertTrue(true); }
                $reset = $powershell->run($script, ['-Mode', 'ResetLinks', '-Root', $root]); self::assertTrue($reset->isSuccessful(), $reset->getErrorOutput());
                self::assertCount(2, $files->inventory($world, ['config/server' => $config])['entries']);
            }
        } finally { $removed = $powershell->run($script, ['-Mode', 'Remove', '-Root', $root]); self::assertTrue($removed->isSuccessful(), $removed->getErrorOutput()); }
    }
}
