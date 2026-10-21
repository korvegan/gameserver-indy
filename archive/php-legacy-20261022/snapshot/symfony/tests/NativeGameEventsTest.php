<?php
declare(strict_types=1);
namespace App\Tests;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class NativeGameEventsTest extends SliceTestCase
{
    #[Group('regular')]
    public function testNativeFileChangeAndOwnerPipeShutdown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('SLICE_WINDOWS_TEST') !== '1') { self::markTestSkipped('Explicit isolated Windows fixture only.'); }
        $log = $this->directory.'/fixture.log'; file_put_contents($log, '');
        $configuration = $this->directory.'/watch.json'; file_put_contents($configuration, json_encode(['profiles'=>[
            ['id'=>'fixture','executable'=>'IndyEventFixture.exe','log'=>$log,'config'=>$this->directory.'/fixture.json']]], JSON_THROW_ON_ERROR));
        $input = new InputStream(); $output = '';
        $process = new Process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',dirname(__DIR__).'/tools/watch-game-events.ps1','-ConfigurationPath',$configuration]);
        $process->setInput($input)->setTimeout(15)->start(function ($type, $bytes) use (&$output): void { $output .= $bytes; });
        try {
            $deadline = microtime(true)+10;
            while (!str_contains($output, 'ready') && $process->isRunning() && microtime(true)<$deadline) { usleep(20000); }
            self::assertStringContainsString('ready', $output);
            file_put_contents($log, "Synthetic readiness line\n", FILE_APPEND);
            $deadline = microtime(true)+3;
            while (!str_contains($output, '"kind":"log"') && $process->isRunning() && microtime(true)<$deadline) { usleep(20000); }
            self::assertStringContainsString('"kind":"log"', $output);
            if (!str_contains($output, '"degraded":true')) {
                $executable = $this->directory.'/IndyEventFixture.exe';
                copy(getenv('SystemRoot').'/System32/where.exe', $executable);
                (new Process([$executable,'/?']))->mustRun(); // Own read-only stand-in; no installed game starts.
                $deadline = microtime(true)+5;
                while (!str_contains($output, '"kind":"process"') && $process->isRunning() && microtime(true)<$deadline) { usleep(20000); }
                self::assertStringContainsString('"kind":"process"', $output);
            }
            $input->close(); $process->wait();
            self::assertSame(0, $process->getExitCode(), $output);
        } finally { $input->close(); $process->stop(1); }
    }
}
