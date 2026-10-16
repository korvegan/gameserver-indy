<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Log\LogText;
use App\Log\ServerLogs;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;
use App\WhatsApp\DeliveryStore;
use App\WhatsApp\LocalWhatsAppClient;
use App\WhatsApp\Readiness;
use App\WhatsApp\WhatsApp;
use App\WhatsApp\WhatsAppClient;
use App\WhatsApp\WhatsAppError;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WhatsAppTest extends SliceTestCase
{
    private const GROUP = '123456789@g.us';
    private function ownStatus(int $pid = 42): array
    {
        return ['known'=>true,'running'=>true,'starting'=>false,'launcher_running'=>false,'pid'=>$pid,'ready'=>null,
            'processes'=>[['pid'=>$pid,'started'=>'2024-01-01T10:00:00.250000Z']],'user_profile'=>$this->directory.'/own-windows-user'];
    }
    private function boot(?WhatsAppClient $client = null, ?ProcessGateway $gateway = null, bool $http = false): mixed
    {
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $browser = $http ? static::createClient([],['HTTP_HOST'=>'127.0.0.1:18865','REMOTE_ADDR'=>'127.0.0.1','INDY_LISTENER_PORT'=>'18865']) : null;
        if ($browser === null) { static::bootKernel(); } else { $browser->disableReboot(); } $container = static::getContainer();
        if ($client === null) { $client = $this->createStub(WhatsAppClient::class); $client->method('configured')->willReturn(true); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]); }
        if ($gateway === null) { $gateway = $this->createStub(ProcessGateway::class); $gateway->method('call')->willReturn($this->ownStatus()); }
        $container->set(WhatsAppClient::class,$client); $container->set(ProcessGateway::class,$gateway); $this->ledger();
        $container->get(DeliveryStore::class)->initialize(); $owner = new Principal('local-owner','owner');
        $directory = $this->directory.'/own-install'; PrivateFiles::directory($directory.'/logs');
        $container->get(InstanceRegistry::class)->register($owner,(object) ['id'=>'fixture','game'=>'enshrouded','label'=>'Own fixture','server_directory'=>$directory]);
        $container->get(InstanceRegistry::class)->saveConnection($owner,'fixture','fixture.example.org');
        $config = (new EnshroudedConfiguration())->initial(); $config->name = 'Own server'; $config->userGroups[0]->password = 'synthetic-password';
        PrivateFiles::write($directory.'/enshrouded_server.json',PrivateFiles::json($config));
        PrivateFiles::write($directory.'/logs/enshrouded_server.log',"Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n");
        $container->get(ProcessObservation::class)->record('fixture',$this->ownStatus()); $container->get(RuntimeLifecycle::class)->open(); return $browser;
    }
    private function enable(): int
    {
        $service = static::getContainer()->get(WhatsApp::class);
        return $service->configure(new Principal('local-owner','owner'),(object) ['group_id'=>self::GROUP,'enabled'=>true,'server_ids'=>['fixture'],'revision'=>$service->settings()->revision])['revision'];
    }
    public function testCurrentStartHeaderAndFinalTransitionIncludingLogGapAreRequired(): void
    {
        $this->boot(); $readiness = static::getContainer()->get(Readiness::class); $path = $this->directory.'/own-install/logs/enshrouded_server.log';
        self::assertSame('ready',$readiness->probe('fixture',$this->ownStatus())['state']);
        foreach (["Current UTC date: 2023.12.31 10:00:00\n[Session] 'HostOnline' (up)!\n",
            "[Session] 'HostOnline' (up)!\nCurrent UTC date: 2024.01.01 10:00:00\n",
            "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n[Session] 'HostOnline' (down)!\n"] as $text) {
            PrivateFiles::write($path,$text); self::assertSame('unknown',$readiness->probe('fixture',$this->ownStatus())['state']);
        }
        $prefix = "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n"; $middle = str_repeat("routine line\n",25000);
        PrivateFiles::write($path,$prefix.$middle."[Session] 'HostOnline' (down)!\n".$middle); self::assertSame('unknown',$readiness->probe('fixture',$this->ownStatus())['state']);
        file_put_contents($path,"[Session] 'HostOnline' (up)!\n",FILE_APPEND); self::assertSame('ready',$readiness->probe('fixture',$this->ownStatus())['state']);
        $offset = 262144-11; PrivateFiles::write($path,$prefix.str_repeat('x',$offset-strlen($prefix)-1)."\n[Session] 'HostOnline' (down)!\n"); self::assertSame('unknown',$readiness->probe('fixture',$this->ownStatus())['state']);
        self::assertNull(Readiness::identity(['processes'=>[['pid'=>42,'started'=>'2999-01-01T00:00:00Z']]]));
        self::assertNull(Readiness::identity(['processes'=>array_fill(0,2,$this->ownStatus()['processes'][0])]));
    }
    public function testExplicitGroupProfilesAndDurableClaimsPreventRepeatedSends(): void
    {
        $messages = []; $client = $this->createMock(WhatsAppClient::class); $client->method('configured')->willReturn(true); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]);
        $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]);
        $client->expects(self::once())->method('send')->willReturnCallback(function ($group,$text) use (&$messages): void { self::assertSame(self::GROUP,$group); $messages[] = $text; });
        $this->boot($client); $service = static::getContainer()->get(WhatsApp::class); $service->tick(); self::assertSame([],$messages);
        $revision = $this->enable(); $service->tick(); $service->tick(); $service->send(new Principal('local-owner','owner'),'fixture',$revision);
        self::assertCount(1,$messages); self::assertCount(3,explode("\n",$messages[0])); self::assertStringContainsString('fixture.example.org:15637',$messages[0]); self::assertStringContainsString('✅ Bereit zum Beitritt',$messages[0]); self::assertStringNotContainsString('synthetic-password',$messages[0]);
        self::assertSame('sent',$service->view(new Principal('local-owner','owner'))['previews'][0]['delivery']);
    }
    public function testUncertainSendAndSendingAfterRestartNeverReplay(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->method('configured')->willReturn(true); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]);
        $client->expects(self::once())->method('send')->willThrowException(new WhatsAppError()); $this->boot($client); $this->enable(); $service = static::getContainer()->get(WhatsApp::class);
        $service->tick(); $service->tick(); self::assertSame('uncertain',$service->view(new Principal('local-owner','owner'))['previews'][0]['delivery']);
        $store = static::getContainer()->get(DeliveryStore::class); $db = DeliveryStore::open($store->path()); $db->exec("UPDATE deliveries SET state='sending'"); $db = null;
        $store->initialize(); self::assertSame('sending',DeliveryStore::open($store->path())->query('SELECT state FROM deliveries')->fetchColumn());
        $service->view(new Principal('local-owner','owner')); $service->tick(); $store->reconcile();
        self::assertSame('uncertain',DeliveryStore::open($store->path())->query('SELECT state FROM deliveries')->fetchColumn());
    }
    public function testFinalNativeStatusAndRuntimeFenceBlockStaleReadyMessages(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]); $client->expects(self::never())->method('send');
        $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::exactly(2))->method('call')->willReturnOnConsecutiveCalls($this->ownStatus(),$this->ownStatus(43));
        $this->boot($client,$gateway); $revision = $this->enable(); $this->expectException(\DomainException::class); static::getContainer()->get(WhatsApp::class)->send(new Principal('local-owner','owner'),'fixture',$revision);
    }
    public function testSettingsRevisionAndDisableRemainSafeWhenServiceIsUnavailable(): void
    {
        $this->boot(); $service = static::getContainer()->get(WhatsApp::class); $revision = $this->enable(); $owner = new Principal('local-owner','owner');
        try { $service->configure($owner,(object) ['group_id'=>self::GROUP,'enabled'=>true,'server_ids'=>['fixture'],'revision'=>0]); self::fail('Old settings overwritten'); } catch (\DomainException) { self::assertSame($revision,$service->settings()->revision); }
        $service->configure($owner,(object) ['group_id'=>self::GROUP,'enabled'=>false,'server_ids'=>['fixture'],'revision'=>$revision]);
        self::assertFalse($service->settings()->enabled); $this->expectException(\DomainException::class); $service->send($owner,'fixture',$revision);
    }
    public function testPersonalAccountIsUnavailableToRemoteAndRestrictedOwners(): void
    {
        $this->boot(); $service = static::getContainer()->get(WhatsApp::class);
        foreach ([new Principal('relative','owner'),new Principal('relative','family'),new Principal('local-owner','owner',['fixture'])] as $actor) {
            try { $service->view($actor); self::fail('Personal account exposed'); } catch (AccessDeniedException) { self::assertTrue(true); }
        }
    }
    public function testFixedLoopbackTransportHasNoProxyRedirectTracingOrRawError(): void
    {
        $runtime = new \App\Runtime\SliceRuntime($this->directory,'simulated','owner'); PrivateFiles::directory($this->directory.'/data/whatsapp'); PrivateFiles::write($this->directory.'/data/whatsapp/credentials.json',PrivateFiles::json((object) ['api_key'=>str_repeat('a',64)]));
        $client = new LocalWhatsAppClient($runtime,new MockHttpClient(function ($method,$url,$options): MockResponse {
            self::assertSame('POST',$method); self::assertSame('http://127.0.0.1:18768/api/sendText',$url); self::assertSame('*',$options['no_proxy']); self::assertSame(0,$options['max_redirects']);
            self::assertSame(['session'=>'default','chatId'=>self::GROUP,'text'=>'Own fixture','linkPreview'=>false],json_decode($options['body'],true));
            self::assertStringContainsString('X-Api-Key: '.str_repeat('a',64),implode("\n",$options['headers'])); return new MockResponse('{"id":"own-message"}');
        })); $client->send(self::GROUP,'Own fixture');
        foreach ([302,500] as $status) { try { (new LocalWhatsAppClient($runtime,new MockHttpClient(new MockResponse('synthetic-private',['http_code'=>$status]))))->send(self::GROUP,'Own fixture'); self::fail('Unexpected HTTP accepted'); } catch (WhatsAppError $error) { self::assertStringNotContainsString('synthetic-private',$error->getMessage()); } }
        self::assertSame(['status'=>'STOPPED','exists'=>false],(new LocalWhatsAppClient($runtime,new MockHttpClient(new MockResponse('', ['http_code'=>404]))))->session());
        $this->expectException(WhatsAppError::class); (new LocalWhatsAppClient($runtime,new MockHttpClient(new MockResponse(str_repeat('x',2097153)))))->send(self::GROUP,'Own fixture');
    }
    public function testGroupsProjectBothEnginesAndNeverExposeParticipants(): void
    {
        PrivateFiles::directory($this->directory.'/data/whatsapp'); PrivateFiles::write($this->directory.'/data/whatsapp/credentials.json',PrivateFiles::json((object) ['api_key'=>str_repeat('a',64)]));
        $http = new MockHttpClient([new MockResponse('{"status":"WORKING"}'),new MockResponse(json_encode([
            ['JID'=>self::GROUP,'Name'=>'Own group','Participants'=>['synthetic-private']],['id'=>['_serialized'=>'987654321@g.us'],'subject'=>'Own group'],['id'=>'123456@c.us','name'=>'Private chat']]))]);
        $client = new LocalWhatsAppClient(new \App\Runtime\SliceRuntime($this->directory,'simulated','owner'),$http);
        $groups = $client->groups(); self::assertCount(2,$groups); self::assertStringNotContainsString('synthetic-private',json_encode($groups));
    }
    public function testBoundedLogTailRedactsCurrentRotatedAndUtf16Credentials(): void
    {
        $this->boot(); $text = "Authentication token = old-token\npassword:\nrotated-password\nhttps://discord.com/api/webhooks/1/rotated\nError: still visible\nSYNTHETIC-PASSWORD\n";
        self::assertStringNotContainsString('old-token',LogText::redact($text)); self::assertStringContainsString('Error: still visible',LogText::redact($text)); self::assertStringNotContainsString('rotated-password',LogText::redact($text));
        $path = $this->directory.'/own-install/logs/enshrouded_server.log'; PrivateFiles::write($path,"\xff\xfe".mb_convert_encoding($text,'UTF-16LE','UTF-8'));
        $view = static::getContainer()->get(ServerLogs::class)->read('fixture'); self::assertStringNotContainsString('SYNTHETIC-PASSWORD',$view['text']); self::assertStringContainsString('Error: still visible',$view['text']);
        PrivateFiles::write($this->directory.'/own-install/enshrouded_server.json','broken'); $view = static::getContainer()->get(ServerLogs::class)->read('fixture'); self::assertStringContainsString('nicht lesbar',$view['text']); self::assertStringNotContainsString('rotated',$view['text']);
    }
    public function testHttpQrAndAccountActionsRequireLocalOwnerAndCsrf(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->expects(self::never())->method('connect'); $browser = $this->boot($client,http:true);
        $browser->request('POST','/api/whatsapp/connect',server:['CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'http://127.0.0.1:18865'],content:'{}'); self::assertResponseStatusCodeSame(403);
        $browser->request('GET','/api/logs?server=fixture'); self::assertResponseIsSuccessful(); self::assertStringNotContainsString('synthetic-password',$browser->getResponse()->getContent());
    }
    public function testClaimFinalizationFailureAndSnapshotKeepUncertainHistoryWithoutCredentials(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]);
        $client->expects(self::once())->method('send')->willReturnCallback(function (): void {
            $path = static::getContainer()->get(DeliveryStore::class)->path(); DeliveryStore::open($path)->exec("CREATE TRIGGER own_fail_finalization BEFORE UPDATE ON deliveries BEGIN SELECT RAISE(FAIL,'own fixture failure'); END");
        });
        $this->boot($client); $revision = $this->enable(); $service = static::getContainer()->get(WhatsApp::class); $owner = new Principal('local-owner','owner'); $store = static::getContainer()->get(DeliveryStore::class);
        try { $service->send($owner,'fixture',$revision); self::fail('Failed finalization accepted'); } catch (\DomainException) { self::assertSame('sending',DeliveryStore::open($store->path())->query('SELECT state FROM deliveries')->fetchColumn()); }
        DeliveryStore::open($store->path())->exec('DROP TRIGGER own_fail_finalization'); self::assertSame('uncertain',$service->view($owner)['previews'][0]['delivery']);
        try { $service->send($owner,'fixture',$revision); self::fail('Ambiguous claim repeated'); } catch (\DomainException) { self::assertTrue(true); }
        PrivateFiles::write(dirname($store->path()).'/credentials.json',PrivateFiles::json((object) ['api_key'=>str_repeat('a',64)]));
        $bytes = PrivateFiles::read(dirname($store->path()).'/settings.json'); $before = hash_file('sha256',$store->path());
        $result = static::getContainer()->get(\App\WhatsApp\WhatsAppSnapshot::class)->copy(dirname($store->path()),$this->directory.'/own-copy'); self::assertSame(1,$result['claims']);
        self::assertSame($before,hash_file('sha256',$store->path())); self::assertSame($bytes,PrivateFiles::read($this->directory.'/own-copy/settings.json')); self::assertFileDoesNotExist($this->directory.'/own-copy/credentials.json');
        self::assertSame('sending',DeliveryStore::open($this->directory.'/own-copy/deliveries.sqlite3')->query('SELECT state FROM deliveries')->fetchColumn());
    }
    public function testShutdownDuringFinalStatusPreventsDelivery(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]); $client->method('session')->willReturn(['status'=>'WORKING','exists'=>true]); $client->expects(self::never())->method('send');
        $calls = 0; $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::exactly(2))->method('call')->willReturnCallback(function () use (&$calls): array { if (++$calls === 2) { static::getContainer()->get(RuntimeLifecycle::class)->close(); } return $this->ownStatus(); });
        $this->boot($client,$gateway); $revision = $this->enable(); $this->expectException(\App\Runtime\OutcomeUnknown::class); static::getContainer()->get(WhatsApp::class)->send(new Principal('local-owner','owner'),'fixture',$revision);
    }
    public function testRemoteOwnerCannotReadPersonalAccountOrUseAValidRemoteTokenToConnect(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->expects(self::never())->method('configured'); $client->expects(self::never())->method('session'); $client->expects(self::never())->method('groups'); $client->expects(self::never())->method('qr'); $client->expects(self::never())->method('connect');
        $browser = $this->boot($client,http:true); $access = static::getContainer()->get(AccessStore::class); $owner = new Principal('local-owner','owner');
        $access->configure($owner,['enabled'=>true,'hostname'=>'fixture.private.ts.net','port'=>18767]);
        $access->saveUser($owner,['login'=>'relative@example.invalid','name'=>'Relative','role'=>'owner'],['fixture']);
        $server = ['HTTP_HOST'=>'fixture.private.ts.net','REMOTE_ADDR'=>'127.0.0.1','INDY_LISTENER_PORT'=>'18767','HTTP_TAILSCALE_USER_LOGIN'=>'relative@example.invalid'];
        foreach (['/api/whatsapp','/api/whatsapp/groups','/api/whatsapp/qr'] as $path) { $browser->request('GET',$path,server:$server); self::assertResponseStatusCodeSame(403); }
        $browser->request('GET','/api/session',server:$server); self::assertResponseIsSuccessful(); $token = json_decode($browser->getResponse()->getContent(),true)['token'];
        $browser->request('POST','/api/whatsapp/connect',server:[...$server,'CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'https://fixture.private.ts.net','HTTP_X_MANAGER_TOKEN'=>$token],content:'{}'); self::assertResponseStatusCodeSame(403);
    }
    public function testGroupChangeDuringReadinessCannotRetargetAManualSend(): void
    {
        $client = $this->createMock(WhatsAppClient::class); $client->method('groups')->willReturn([['id'=>self::GROUP,'label'=>'Own group']]); $client->expects(self::never())->method('session'); $client->expects(self::never())->method('send');
        $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::once())->method('call')->willReturnCallback(function (): array {
            $path = $this->directory.'/data/whatsapp/settings.json'; $settings = PrivateFiles::object(PrivateFiles::read($path)); ++$settings->revision; $settings->group_id = '987654321@g.us'; PrivateFiles::write($path,PrivateFiles::json($settings)); return $this->ownStatus();
        });
        $this->boot($client,$gateway); $revision = $this->enable(); $this->expectException(\DomainException::class); static::getContainer()->get(WhatsApp::class)->send(new Principal('local-owner','owner'),'fixture',$revision);
    }
    public function testAskaReadyMarkerUsesTheRetainedDiagnosticDirectory(): void
    {
        $this->boot(); $container = static::getContainer(); $owner = new Principal('local-owner','owner');
        $directory = $this->directory.'/own-aska'; $diagnostic = $this->directory.'/retained-runner-logs'; PrivateFiles::directory($directory); PrivateFiles::directory($diagnostic);
        $container->get(InstanceRegistry::class)->register($owner,(object) ['id'=>'own-aska','game'=>'aska','label'=>'Own ASKA','server_directory'=>$directory]);
        $settingsPath = $this->directory.'/manager.local.json'; $settings = PrivateFiles::object(PrivateFiles::read($settingsPath)); $settings->servers[1]->diagnostic_directory = $diagnostic; PrivateFiles::write($settingsPath,PrivateFiles::json($settings));
        $config = (new \App\Game\AskaConfiguration())->initial(); PrivateFiles::write($directory.'/server properties.txt',(new \App\Game\AskaConfiguration())->encode($config,''));
        PrivateFiles::write($diagnostic.'/server.log',"2024-01-01T09:59:59Z The session is now open!\n"); self::assertSame('unknown',$container->get(Readiness::class)->probe('own-aska',$this->ownStatus())['state']);
        PrivateFiles::write($diagnostic.'/server.log',"2024-01-01T10:00:01Z The session is now open!\n"); self::assertSame('ready',$container->get(Readiness::class)->probe('own-aska',$this->ownStatus())['state']);
        $snapshot = $container->get(WhatsApp::class)->snapshot('own-aska'); self::assertStringContainsString('Serverliste',$snapshot['message']); self::assertStringNotContainsString('27016',$snapshot['message']);
        self::assertSame('server.log',$container->get(ServerLogs::class)->read('own-aska')['file']);
    }
    public function testWhatsAppClientHasNoFrameworkLoggerOrTraceWrapper(): void
    {
        static::bootKernel(); $transport = static::getContainer()->get(LocalWhatsAppClient::class); $client = (new \ReflectionProperty($transport,'client'))->getValue($transport);
        self::assertNotInstanceOf(\Symfony\Component\HttpClient\TraceableHttpClient::class,$client); self::assertNotInstanceOf(\Symfony\Component\HttpClient\RetryableHttpClient::class,$client);
        self::assertNull((new \ReflectionProperty($client,'logger'))->getValue($client));
    }
    public function testUnknownSnapshotStructureIsRejectedAndOnlyItsNewCopyIsRemoved(): void
    {
        $this->boot(); $this->enable(); $source = $this->directory.'/own-invalid-source'; PrivateFiles::directory($source);
        $bytes = PrivateFiles::read($this->directory.'/data/whatsapp/settings.json'); PrivateFiles::write($source.'/settings.json',$bytes);
        DeliveryStore::open($source.'/deliveries.sqlite3')->exec('CREATE TABLE unrelated (value TEXT)'); $before = hash_file('sha256',$source.'/deliveries.sqlite3');
        try { static::getContainer()->get(\App\WhatsApp\WhatsAppSnapshot::class)->copy($source,$this->directory.'/own-rejected-copy'); self::fail('Unknown source accepted'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($this->directory.'/own-rejected-copy'); self::assertSame($before,hash_file('sha256',$source.'/deliveries.sqlite3')); self::assertSame($bytes,PrivateFiles::read($source.'/settings.json')); }
    }
}
