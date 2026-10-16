<?php
declare(strict_types=1);
namespace App\Tests;
use App\Access\Principal;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Job\JobService;
use App\Runtime\ProcessGateway;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;

final class DashboardTest extends SliceTestCase
{
    private function boot(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $client = static::createClient([],['HTTP_HOST'=>'127.0.0.1:18865','REMOTE_ADDR'=>'127.0.0.1','INDY_LISTENER_PORT'=>'18865']); $client->disableReboot();
        $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::never())->method('call'); static::getContainer()->set(ProcessGateway::class,$gateway); $this->ledger();
        $owner = new Principal('local-owner','owner');
        foreach (['first','second'] as $id) {
            $directory = $this->directory.'/'.$id; PrivateFiles::directory($directory);
            static::getContainer()->get(InstanceRegistry::class)->register($owner,(object) ['id'=>$id,'game'=>'enshrouded','label'=>$id,'server_directory'=>$directory]);
            $config = (new EnshroudedConfiguration())->initial(); $config->name = 'Own '.$id; $config->userGroups[0]->password = 'synthetic-secret';
            PrivateFiles::write($directory.'/enshrouded_server.json',PrivateFiles::json($config));
        }
        static::getContainer()->get(RuntimeLifecycle::class)->open(); return $client;
    }
    public function testManagedRootRendersFullTwigDashboardAndOnlyWhitelistedAssets(): void
    {
        $client = $this->boot(); $client->request('GET','/'); self::assertResponseIsSuccessful(); self::assertSelectorExists('#server-select'); self::assertSelectorExists('#community'); self::assertSelectorExists('#whatsapp'); self::assertSelectorExists('#new-moment[disabled]');
        foreach (['/app.js','/job-stream.js','/views/story-art.js','/style.css','/favicon.svg'] as $path) { $client->request('GET',$path); self::assertResponseIsSuccessful(); }
        $client->request('GET','/views/../../composer.lock'); self::assertResponseStatusCodeSame(404);
    }
    public function testOverviewNeverQueriesProcessesAndBrokenConfigDoesNotBreakCatalog(): void
    {
        $client = $this->boot(); $client->request('GET','/api/status?server=first'); self::assertResponseIsSuccessful(); $data = json_decode($client->getResponse()->getContent(),true);
        self::assertFalse($data['status']['known']); self::assertNull($data['status']['running']); self::assertSame('Own first',$data['summary']['title']); self::assertStringNotContainsString('synthetic-secret',$client->getResponse()->getContent());
        PrivateFiles::write($this->directory.'/first/enshrouded_server.json','broken'); $client->request('GET','/api/status?server=first'); self::assertResponseIsSuccessful();
        self::assertNotNull(json_decode($client->getResponse()->getContent(),true)['config_error']);
        $client->request('GET','/api/servers'); self::assertResponseIsSuccessful(); self::assertCount(2,json_decode($client->getResponse()->getContent(),true)['servers']);
        $client->request('GET','/api/status?server=second'); self::assertResponseIsSuccessful(); self::assertNull(json_decode($client->getResponse()->getContent(),true)['config_error']);
    }
    public function testSelectedJobSnapshotKeepsOtherProfilesOutBeforeTheLimit(): void
    {
        $client = $this->boot(); $service = static::getContainer()->get(JobService::class);
        $service->submit('first','start',str_repeat('a',32)); $service->submit('second','start',str_repeat('b',32));
        $client->request('GET','/api/jobs?server=first'); self::assertResponseIsSuccessful(); $data = json_decode($client->getResponse()->getContent(),true);
        self::assertCount(1,$data['jobs']); self::assertSame('first',$data['jobs'][0]['server_id']); self::assertGreaterThanOrEqual(2,$data['cursor']);
    }
    public function testTechnicalHistoryRedactsCurrentSecretsAndListenerClaimsExpireWithEpoch(): void
    {
        $client = $this->boot(); $path = $this->directory.'/data/servers/first'; PrivateFiles::directory($path);
        PrivateFiles::write($path.'/events.jsonl',json_encode(['time'=>'2026-10-16T00:00:00Z','message'=>'Own synthetic-secret old event'])."\n");
        $client->request('GET','/api/events?server=first'); self::assertResponseIsSuccessful(); self::assertStringNotContainsString('synthetic-secret',$client->getResponse()->getContent());
        $epoch = static::getContainer()->get(RuntimeLifecycle::class)->epoch(); PrivateFiles::write($this->directory.'/listener-runtime.json',PrivateFiles::json((object) ['active'=>true,'hostname'=>'fixture.private.ts.net','port'=>18767,'epoch'=>$epoch]));
        $client->request('GET','/api/access'); self::assertResponseIsSuccessful(); self::assertTrue(json_decode($client->getResponse()->getContent(),true)['runtime']['active']);
        static::getContainer()->get(RuntimeLifecycle::class)->close(); $client->request('GET','/api/access'); self::assertFalse(json_decode($client->getResponse()->getContent(),true)['runtime']['active']);
    }
}
