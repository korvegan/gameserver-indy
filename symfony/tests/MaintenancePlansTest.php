<?php
declare(strict_types=1);
namespace App\Tests;
use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Runtime\MaintenancePlans;
use App\Runtime\RuntimeLifecycle;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
final class MaintenancePlansTest extends SliceTestCase
{
    protected function tearDown(): void { Clock::set(new NativeClock()); parent::tearDown(); }
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testUnknownOccupancyWaitsThenApprovedCountdownQueuesExactlyOnceAndCanBeCancelled(): void
    {
        $clock=new MockClock('2026-10-17T02:00:00Z'); Clock::set($clock);
        $_ENV['SLICE_DRIVER']=$_SERVER['SLICE_DRIVER']='managed'; putenv('SLICE_DRIVER=managed'); static::bootKernel(); $this->ledger(); $c=static::getContainer();
        $owner=new Principal('local-owner','owner');
        $settings=(object)['schema_version'=>2,'servers'=>[(object)['id'=>'own-maintenance','game'=>'enshrouded','label'=>'Own maintenance','server_directory'=>$this->directory.'/game']]];
        \App\Storage\PrivateFiles::write($this->directory.'/manager.local.json',\App\Storage\PrivateFiles::json($settings));
        $c->get(RuntimeLifecycle::class)->open(); $plans=$c->get(MaintenancePlans::class);
        $plans->save($owner,'own-maintenance',(object)['action'=>'create','operation'=>'stop','delay'=>60]);
        $cancel=$plans->view($owner,'own-maintenance')['plan']; $plans->save($owner,'own-maintenance',(object)['action'=>'cancel','id'=>$cancel['id']]);
        self::assertSame('cancelled',$plans->view($owner,'own-maintenance')['plan']['state']);
        $plans->save($owner,'own-maintenance',(object)['action'=>'create','operation'=>'restart','delay'=>60]); $clock->sleep(120); $plans->tick();
        $view=$plans->view($owner,'own-maintenance'); self::assertNull($view['players']['count']); self::assertSame('waiting_approval',$view['plan']['state']);
        self::assertSame(0,$c->get(\App\Job\JobRepository::class)->pendingCount());
        $plans->save($owner,'own-maintenance',(object)['action'=>'approve','id'=>$view['plan']['id']]); $clock->sleep(59); $plans->tick();
        self::assertSame('countdown',$plans->view($owner,'own-maintenance')['plan']['state']);
        $clock->sleep(1); $plans->tick(); $plans->tick(); self::assertSame(1,$c->get(\App\Job\JobRepository::class)->pendingCount());
        self::assertSame('queued',$plans->view($owner,'own-maintenance')['plan']['state']);
        $this->expectException(\DomainException::class);
        $plans->save($owner,'own-maintenance',(object)['action'=>'create','operation'=>'stop','delay'=>60]);
    }
}
