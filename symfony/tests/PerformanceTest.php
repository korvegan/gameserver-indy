<?php
declare(strict_types=1);
namespace App\Tests;
use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Runtime\PerformanceHistory;
use App\Runtime\SliceRuntime;
use Symfony\Component\Clock\MockClock;

final class PerformanceTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testHistoryNormalizesCpuTracksMemoryAndMarksStuttersWithoutMixingProcessStarts(): void
    {
        static::bootKernel(); $this->ledger(); $registry=static::getContainer()->get(InstanceRegistry::class);
        $registry->register(new Principal('local-owner','owner'),(object)['id'=>'own','label'=>'Own','game'=>'enshrouded','server_directory'=>$this->directory.'/own']);
        $clock=new MockClock('2026-10-17T00:00:00Z');
        $history=new PerformanceHistory(static::getContainer()->get(SliceRuntime::class),$registry,$clock);
        $status=['known'=>true,'running'=>true,'processes'=>[['pid'=>42,'started'=>'2026-10-17T00:00:00Z','cpu_seconds'=>10.0,'memory_bytes'=>1073741824,'io_bytes'=>1000]],
            'host_metrics'=>['logical_processors'=>8,'cpu_percent'=>60,'memory_used_bytes'=>8589934592,'memory_total_bytes'=>17179869184]];
        $history->record('own',$status); self::assertNull($history->view('own')['samples'][0]['cpu_percent']);
        $clock->sleep(8); $status['processes'][0]['cpu_seconds']=14.0; $status['processes'][0]['io_bytes']=9000;
        $history->record('own',$status); $sample=$history->view('own')['samples'][0];
        self::assertEqualsWithDelta(6.25,$sample['cpu_percent'],0.001); self::assertEquals(1000,$sample['io_bytes_sec']);
        self::assertSame(1073741824,$sample['memory_bytes']); self::assertFalse($history->view('own')['stale']);
        $history->mark('own','Client aktiv, gerade geruckelt');
        self::assertSame('stutter',$history->view('own')['markers'][1]['type']);
        $clock->sleep(60); $status['processes'][0]['pid']=43; $history->record('own',$status);
        self::assertNull($history->view('own')['samples'][1]['cpu_percent']);
        $clock->sleep(31); self::assertTrue($history->view('own')['stale']);
        $history->record('own',['known'=>false,'running'=>null,'processes'=>[]]);
        self::assertNull($history->view('own')['samples'][1]['memory_bytes']);
    }
}
