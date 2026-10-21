<?php
declare(strict_types=1);
namespace App\Tests;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class LinuxGameEventsTest extends SliceTestCase
{
    public function testActualWslSourceRoutesLocalLogsToInotify(): void
    {
        if (!is_file('/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe')) { self::markTestSkipped('Own WSL/Windows host check only.'); }
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed'); $this->ledger();
        $container = static::getContainer(); $directory = $this->directory.'/own-install'; \App\Storage\PrivateFiles::directory($directory.'/logs');
        $container->get(\App\Instance\InstanceRegistry::class)->register(new \App\Access\Principal('local-owner','owner'),
            (object)['id'=>'fixture','game'=>'enshrouded','label'=>'Own watcher fixture','server_directory'=>$directory]);
        file_put_contents($directory.'/enshrouded_server.json', \App\Storage\PrivateFiles::json((new \App\Game\EnshroudedConfiguration())->initial()));
        $log = $directory.'/logs/enshrouded_server.log'; file_put_contents($log, '');
        $source = $container->get(\App\Runtime\NativeEventSource::class);
        try {
            $source->start(); $ready = []; $deadline = microtime(true)+10;
            while (count($ready)<count($source->streams()) && microtime(true)<$deadline) {
                $read = array_values($source->streams()); $write = $except = []; stream_select($read,$write,$except,1);
                foreach ($read as $stream) foreach ($source->read($stream) as $event) {
                    if ($event['kind']==='ready') { self::assertFalse($event['degraded']); $ready[$event['source']] = true; }
                }
            }
            self::assertCount(2,$ready);
            file_put_contents($log,"Own WSL append\n",FILE_APPEND); $found = false; $deadline = microtime(true)+3;
            while (!$found && microtime(true)<$deadline) {
                $read = array_values($source->streams()); $write = $except = []; stream_select($read,$write,$except,1);
                foreach ($read as $stream) foreach ($source->read($stream) as $event) { if ($event['kind']==='log' && $event['server_id']==='fixture') $found = true; }
            }
            self::assertTrue($found, 'A WSL-local game log must produce an immediate native event.');
        } finally { $source->close(); }
    }
    #[Group('regular')]
    public function testWslLocalLogChangesAndRotationArriveWithoutPolling(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Linux inotify bridge only.'); }
        $binary = $this->directory.'/watch-files'; $log = $this->directory.'/server.log'; file_put_contents($log, '');
        (new Process(['cc','-O2','-Wall','-Wextra','-Werror',dirname(__DIR__).'/tools/watch-files.c','-o',$binary]))->mustRun();
        $input = new InputStream(); $output = '';
        $process = new Process([$binary,'log:fixture',$log]); $process->setInput($input)->setTimeout(5)->start(function ($type,$bytes) use (&$output): void { $output .= $bytes; });
        try {
            $deadline = microtime(true)+2;
            while (!str_contains($output,'ready') && $process->isRunning() && microtime(true)<$deadline) { usleep(10000); }
            self::assertStringContainsString('"degraded":false', $output);
            file_put_contents($log,"Own WSL log append\n",FILE_APPEND);
            $deadline = microtime(true)+2;
            while (!str_contains($output,'"kind":"log"') && $process->isRunning() && microtime(true)<$deadline) { usleep(10000); }
            self::assertStringContainsString('"kind":"log"', $output);
            $output = ''; rename($log,$log.'.old'); file_put_contents($log,"New log file\n");
            $deadline = microtime(true)+2;
            while (!str_contains($output,'"kind":"log"') && $process->isRunning() && microtime(true)<$deadline) { usleep(10000); }
            self::assertStringContainsString('"kind":"log"', $output);
            $input->close(); $process->wait(); self::assertSame(0,$process->getExitCode());
        } finally { $input->close(); $process->stop(1); }
    }
}
