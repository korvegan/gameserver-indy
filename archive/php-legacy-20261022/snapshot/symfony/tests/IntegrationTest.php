<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Integration\Connections;
use App\Integration\Discord;
use App\Integration\DiscordTransport;
use App\Integration\DiscordWebhook;
use App\Integration\HostNetworkProvider;
use App\Integration\ManagerSettings;
use App\Integration\SteamCmd;
use App\Integration\SteamInstallation;
use App\Integration\SteamKeyValues;
use App\Integration\SteamProvider;
use App\Integration\SteamUpdates;
use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobReconciler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\OutcomeUnknown;
use App\Runtime\ProcessGateway;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\ServerControl;
use App\Storage\PrivateFiles;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class IntegrationTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testCommunityCardPublishesFourMembersWithAttachmentAndEditsSameMessage(): void
    {
        $calls=[]; $jpeg=hex2bin('ffd8ffc00011080001000103011100021100031100ffda0008010100003f0000ffd9');
        $transport=$this->createMock(DiscordTransport::class);
        $transport->expects(self::exactly(2))->method('card')->willReturnCallback(function($url,$payload,$image,$remote) use (&$calls,$jpeg) {
            self::assertSame('https://discord.com/api/webhooks/456/community',$url); self::assertSame($jpeg,$image);
            self::assertSame('attachment://preview.jpg',$payload['embeds'][0]['image']['url']);
            self::assertStringContainsString('Person Vier',$payload['embeds'][0]['fields'][1]['value']);
            self::assertSame('https://own.test.ts.net/#scene-own-community-card',$payload['embeds'][0]['url']);
            self::assertSame($calls ? '65432' : null,$remote); $calls[]=$payload; return '65432';
        });
        $this->boot(discord:$transport); $container=static::getContainer(); $container->get(\App\Community\PlatformDatabase::class)->initialize();
        $owner=new Principal('local-owner','owner');
        $container->get(AccessStore::class)->configure($owner,['enabled'=>true,'hostname'=>'own.test.ts.net','port'=>18877]);
        $publisher=$container->get(\App\Community\DiscordPublication::class);
        $publisher->configure($owner,(object)['webhook_url'=>'https://discord.com/api/webhooks/456/community','allow_media'=>true]);
        $writer=$container->get(\App\Community\ChronicleWriter::class);
        $event=(object)['id'=>'own-community-card','revision'=>1,'game_id'=>'enshrouded','title'=>'Unser Winter','badge'=>'Zusammen',
            'members'=>['Person Eins','Person Zwei','Person Drei','Person Vier'],'image'=>base64_encode($jpeg)];
        $writer->save($owner,$event); $settings=$publisher->view($owner);
        $payload=(object)['kind'=>'event','event_id'=>$event->id,'revision'=>1,'media'=>true,'replace'=>false,
            'destination_revision'=>$settings['destination_revision'],'generation'=>'new'];
        $job=$container->get(JobService::class)->submit('fixture','community_discord',str_repeat('e',32),$owner,$payload);
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('succeeded',$container->get(JobRepository::class)->get($job['id'])['state']);
        $publisher->execute($owner,$payload,static function() {}); self::assertCount(1,$calls);
        $event->revision=2; $event->reason='Titel präzisiert'; $event->title='Unser erster Winter'; $writer->save($owner,$event);
        $payload->revision=2; $payload->generation=$publisher->generation($event->id); $publisher->execute($owner,$payload,static function() {});
        self::assertCount(2,$calls); self::assertSame('Unser erster Winter',$calls[1]['embeds'][0]['title']);
        $view=$publisher->view($owner); self::assertSame('sent',$view['publications'][0]['state']); self::assertSame('65432',$view['publications'][0]['remote_id']);
        self::assertStringNotContainsString('/api/webhooks/',json_encode($view));
        self::assertSame(1,$container->get(\App\Community\ChronicleReader::class)->page($owner)['total']);
    }
    public function testCommunitySummaryRemainsAddressableBeyondRecentPublicationHistory(): void
    {
        $this->boot(); $container=static::getContainer(); $container->get(\App\Community\PlatformDatabase::class)->initialize();
        $db=$container->get(\Doctrine\DBAL\Connection::class);
        foreach (array_merge(['community-summary'],array_map(static fn($i)=>'moment-'.$i,range(1,30))) as $i=>$id) {
            $db->insert('community_discord_messages',['event_id'=>$id,'remote_id'=>(string)(70000+$i),'revision'=>1,'state'=>'sent','media'=>0,
                'template_version'=>1,'requested_by'=>'local-owner','attempted_at'=>$i,'next_try'=>0,'error'=>null,'webhook_hash'=>'fixture']);
        }
        $publisher=$container->get(\App\Community\DiscordPublication::class); $view=$publisher->view(new Principal('local-owner','owner'));
        $summary=array_values(array_filter($view['publications'],static fn($row)=>$row['event_id']==='community-summary'));
        self::assertCount(1,$summary); self::assertSame($publisher->generation('community-summary'),$summary[0]['generation']);
    }
    public function testCommunityCardHttpMultipartUsesFixedAttachmentAndNoMentions(): void
    {
        $jpeg=hex2bin('ffd8ffc00011080001000103011100021100031100ffda0008010100003f0000ffd9');
        $transport=new DiscordWebhook(new MockHttpClient(function($method,$url,$options) use ($jpeg) {
            self::assertSame('POST',$method); self::assertStringContainsString('name="payload_json"',$options['body']);
            self::assertStringContainsString('name="files[0]"; filename="preview.jpg"',$options['body']);
            self::assertStringContainsString('"allowed_mentions":{"parse":[]}',$options['body']); self::assertStringContainsString($jpeg,$options['body']);
            return new MockResponse('{"id":"65432"}');
        }));
        self::assertSame('65432',$transport->card('https://discord.com/api/webhooks/456/community',['embeds'=>[['title'=>'Own card']]],$jpeg));
    }
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testStatusCardCreatesOnceThenEditsSameMessageAcrossPollingAndReconstruction(): void
    {
        $messages=[];
        $transport=$this->createMock(DiscordTransport::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(function($url,$text) use (&$messages) { $messages[]=$text; return '7654'; });
        $transport->expects(self::once())->method('edit')->with('https://discord.com/api/webhooks/123/synthetic','7654',self::callback(function($text) use (&$messages) { $messages[]=$text; return str_contains($text,'Sitzung bereit'); }))->willReturn('7654');
        $client=$this->boot(discord:$transport,http:true); $container=static::getContainer(); $owner=new Principal('local-owner','owner');
        $container->get(Discord::class)->save($owner,'fixture',(object)['auto_notify'=>true,'webhook_url'=>'https://discord.com/api/webhooks/123/synthetic']);
        $card=$container->get(\App\Integration\DiscordStatusCard::class);
        $job=$container->get(JobService::class)->submit('fixture','discord_card_create',str_repeat('c',32),$owner,(object)['replace'=>false,'generation'=>'new']);
        self::assertSame('metadata',$container->get(Connection::class)->fetchOne('SELECT queue_name FROM messenger_messages LIMIT 1'));
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('succeeded',$container->get(JobRepository::class)->get($job['id'])['state']);
        $card->execute('fixture',false,false,static function() {}); // No second message or edit for identical observation.
        $status=['known'=>true,'running'=>true,'processes'=>[['pid'=>42,'started'=>gmdate('Y-m-d\TH:i:s\Z',time()-10)]]];
        $config=\App\Storage\PrivateFiles::object(\App\Storage\PrivateFiles::read($this->directory.'/steamapps/common/Fixture/enshrouded_server.json'));
        $config->logDirectory='./logs'; PrivateFiles::write($this->directory.'/steamapps/common/Fixture/enshrouded_server.json',PrivateFiles::json($config));
        PrivateFiles::directory($this->directory.'/steamapps/common/Fixture/logs');
        PrivateFiles::write($this->directory.'/steamapps/common/Fixture/logs/enshrouded_server.log','Current UTC date: '.gmdate('Y.m.d H:i:s',time()-10)."\n[Session] 'HostOnline' (up)!");
        $container->get(\App\Runtime\ProcessObservation::class)->record('fixture',$status);
        $card->execute('fixture',false,false,static function() {});
        $card->execute('fixture',false,false,static function() {});
        self::assertCount(2,$messages); self::assertStringContainsString('fixture.example.org:15637',$messages[1]);
        self::assertStringNotContainsString('synthetic-secret',implode('',$messages));
        $client->request('GET','/api/discord?server=fixture'); self::assertResponseIsSuccessful();
        $data=json_decode($client->getResponse()->getContent(),true); self::assertSame('7654',$data['card']['message_id']);
        self::assertSame('active',$data['card']['state']); self::assertStringNotContainsString('/api/webhooks/', $client->getResponse()->getContent());
        $replacement=$container->get(JobService::class)->submit('fixture','discord_card_create',str_repeat('d',32),$owner,(object)['replace'=>true,'generation'=>$data['card']['generation']]);
        $card->disable($owner,'fixture');
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($replacement['id']));
        self::assertFalse($card->active('fixture')); self::assertSame([],$card->due());
    }
    public function testStatusCardHttpEditSuppressesMentionsAndRespectsRateLimit(): void
    {
        $transport=new DiscordWebhook(new MockHttpClient(function($method,$url,$options) {
            self::assertSame('PATCH',$method); self::assertStringEndsWith('/messages/7654',$url);
            self::assertSame([],json_decode($options['body'],true)['allowed_mentions']['parse']);
            return new MockResponse('{"id":"7654"}');
        }));
        self::assertSame('7654',$transport->edit('https://discord.com/api/webhooks/123/synthetic','7654','Own fixture'));
        $transport=new DiscordWebhook(new MockHttpClient(new MockResponse('', ['http_code'=>429,'response_headers'=>['Retry-After: 91']])));
        try { $transport->edit('https://discord.com/api/webhooks/123/synthetic','7654','Own fixture'); self::fail('Rate limit ignored'); }
        catch (\App\Integration\DiscordRateLimit $error) { self::assertSame(91,$error->seconds); }
    }
    private function boot(?SteamProvider $steam = null, ?HostNetworkProvider $network = null, ?DiscordTransport $discord = null, bool $http = false, ?ProcessGateway $gateway = null): mixed
    {
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $client = $http ? static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']) : null;
        if ($client === null) { static::bootKernel(); } else { $client->disableReboot(); }
        $container = static::getContainer();
        if ($steam === null) { $steam = $this->createMock(SteamProvider::class); $steam->expects(self::never())->method('query'); }
        if ($network === null) { $network = $this->createMock(HostNetworkProvider::class); $network->expects(self::never())->method('collect'); }
        if ($discord === null) { $discord = $this->createMock(DiscordTransport::class); $discord->expects(self::never())->method('send'); }
        $container->set(SteamProvider::class, $steam); $container->set(HostNetworkProvider::class, $network); $container->set(DiscordTransport::class, $discord);
        if ($gateway === null) { $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::never())->method('call'); }
        $container->set(ProcessGateway::class, $gateway);
        $this->ledger();
        $directory = $this->directory.'/steamapps/common/Fixture'; PrivateFiles::directory($directory);
        $container->get(InstanceRegistry::class)->register(new Principal('local-owner', 'owner'), (object) [
            'id' => 'fixture', 'game' => 'enshrouded', 'label' => 'Own fixture', 'server_directory' => $directory]);
        $config = (new EnshroudedConfiguration())->initial(); $config->name = 'Own Server';
        $config->userGroups[0]->password = 'synthetic-secret'; PrivateFiles::write($directory.'/enshrouded_server.json', PrivateFiles::json($config));
        $container->get(InstanceRegistry::class)->saveConnection(new Principal('local-owner', 'owner'), 'fixture', 'fixture.example.org');
        $this->manifest(); $container->get(RuntimeLifecycle::class)->open();
        return $client;
    }
    private function manifest(string $extra = '', string $build = '100', string $folder = 'Fixture'): void
    {
        PrivateFiles::write($this->directory.'/steamapps/appmanifest_2278520.acf', '"AppState" { "appid" "2278520" "installdir" "'.$folder.'" "buildid" "'.$build.'" "StateFlags" "4" "LastUpdated" "1" '.$extra.' }');
    }
    public function testQuotedSteamParserAndOnlyAnonymousReadCommands(): void
    {
        self::assertSame(['a' => 'x"y\\z'], SteamKeyValues::named('"AppState" { // comment'."\n".'"A" "x\\"y\\\\z" }', 'AppState'));
        self::assertSame(['own.exe', '+login', 'anonymous', '+app_info_update', '1', '+app_info_print', '2278520', '+quit'], SteamCmd::arguments('own.exe', 2278520));
        foreach (['"AppState" { "A" "1" "a" "2" }', '"AppState" { "a" {', str_repeat('"AppState" {}' . "\n", 2)] as $text) {
            try { SteamKeyValues::named($text, 'AppState'); self::fail('Ambiguous/truncated Steam data accepted'); } catch (\DomainException) { self::assertTrue(true); }
        }
    }
    public function testInstalledBuildBranchAndBusyStateRequireMatchingManifest(): void
    {
        $this->boot(); $installed = static::getContainer()->get(SteamInstallation::class);
        self::assertSame('100', $installed->read('fixture')['build']);
        $this->manifest('"TargetBuildID" "200" "BytesToDownload" "20" "BytesDownloaded" "1"');
        self::assertTrue($installed->read('fixture')['busy']); self::assertFalse($installed->read('fixture')['complete']);
        $this->manifest('"UserConfig" { "BetaKey" "preview" } "MountedConfig" { "BetaKey" "public" }');
        try { $installed->read('fixture'); self::fail('Unfinished branch switch accepted'); } catch (\DomainException) { self::assertTrue(true); }
        $this->manifest(folder: 'Other'); $this->expectException(\DomainException::class); $installed->read('fixture');
    }
    public function testSteamEvidenceCacheThrottleFailureAndVersionRemainDistinct(): void
    {
        $calls = 0; $provider = $this->createMock(SteamProvider::class); $provider->expects(self::exactly(2))->method('query')->with(2278520, 'public')
            ->willReturnCallback(function () use (&$calls): string { if (++$calls === 2) { throw new \RuntimeException('synthetic provider secret'); } return '200'; });
        $this->boot(steam: $provider); $updates = static::getContainer()->get(SteamUpdates::class);
        PrivateFiles::write($this->directory.'/steamapps/common/Fixture/enshrouded_server_readme.txt', "Version: 1.0.0.0\n");
        self::assertSame('unknown', $updates->view('fixture')['state']);
        $first = $updates->check('fixture')['update']; self::assertSame('update_available', $first['state']); self::assertSame('100', $first['installed_build']); self::assertSame('200', $first['available_build']); self::assertSame('1.0.0.0', $first['version']);
        $updates->check('fixture'); self::assertSame(1, $calls);
        $path = $this->directory.'/data/steam-updates.json'; $cache = PrivateFiles::object(PrivateFiles::read($path)); $cache->entries->fixture->last_attempt = '2000-01-01T00:00:00Z'; PrivateFiles::write($path, PrivateFiles::json($cache));
        try { $updates->check('fixture'); self::fail('Failed provider accepted'); } catch (\DomainException $error) { self::assertStringNotContainsString('synthetic provider secret', $error->getMessage()); }
        self::assertSame('stale', $updates->view('fixture')['state']); self::assertSame('200', $updates->view('fixture')['available_build']);
        $updates->check('fixture'); self::assertSame(2, $calls);
        $this->manifest('"TargetBuildID" "200" "BytesToDownload" "20" "BytesDownloaded" "1"'); self::assertSame('updating', $updates->view('fixture')['state']);
    }
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testAddressReadsNeverCallProviderAndDomainIsNotReachability(): void
    {
        $network = $this->createMock(HostNetworkProvider::class); $network->expects(self::once())->method('collect')->willReturn([
            'lan' => ['192.168.178.22', '127.0.0.1', '169.254.1.2', '224.0.0.1', '192.168.178.22'], 'public' => '8.8.8.8', 'secret' => 'never expose']);
        $this->boot(network: $network); $connections = static::getContainer()->get(Connections::class);
        $before = $connections->view('fixture'); self::assertSame([], $before['lan']); self::assertFalse($before['external_verified']);
        self::assertSame('fixture.example.org:15637', $before['public_address']); self::assertStringNotContainsString('synthetic-secret', $before['share_message']);
        $connections->refresh(); $after = $connections->view('fixture'); self::assertSame(['192.168.178.22:15637'], $after['lan']);
        self::assertSame('8.8.8.8:15637', $after['external_ip']); self::assertStringNotContainsString('never expose', json_encode($after));
        $path = $this->directory.'/data/host-network.json'; $cache = PrivateFiles::object(PrivateFiles::read($path)); $cache->collected -= 1000; PrivateFiles::write($path, PrivateFiles::json($cache));
        self::assertTrue($connections->view('fixture')['stale']); self::assertSame([], $connections->view('fixture')['lan']); self::assertNull($connections->view('fixture')['external_ip']);
    }
    public function testMetadataClaimDoesNotBlockControlOrInterruptItsFollowers(): void
    {
        $this->boot(); $container = static::getContainer(); $service = $container->get(JobService::class); $jobs = $container->get(JobRepository::class);
        $metadata = $service->submit('fixture', 'check_updates', str_repeat('a', 32)); $jobs->transition($metadata['id'], 'running', 'Own pending provider');
        self::assertSame('metadata', $container->get(Connection::class)->fetchOne('SELECT queue_name FROM messenger_messages LIMIT 1'));
        $lock = (new LockFactory(new FlockStore($this->directory.'/locks')))->createLock('execute:metadata:fixture'); self::assertTrue($lock->acquire());
        try {
            $start = $service->submit('fixture', 'start', str_repeat('b', 32));
            $control = $this->createMock(ServerControl::class); $control->expects(self::once())->method('execute')->with('fixture', 'start');
            ($this->handler($control))(new ExecuteServerJob($start['id']));
            self::assertSame('succeeded', $jobs->get($start['id'])['state']);
            $container->get(JobReconciler::class)->reconcile(); self::assertSame('running', $jobs->get($metadata['id'])['state']);
        } finally { $lock->release(); }
        $stop = $service->submit('fixture', 'stop', str_repeat('c', 32)); $container->get(JobReconciler::class)->reconcile();
        self::assertSame('interrupted', $jobs->get($metadata['id'])['state']); self::assertSame('queued', $jobs->get($stop['id'])['state']);
    }
    public function testDiscordSettingsAndDurableMessageClaimNeverLeakOrReplay(): void
    {
        $transport = $this->createMock(DiscordTransport::class); $transport->expects(self::once())->method('send')->with('https://discord.com/api/webhooks/123/synthetic', 'Own fixture message')->willReturn('123456');
        $this->boot(discord: $transport); $container = static::getContainer(); $owner = new Principal('local-owner', 'owner');
        $discord = $container->get(Discord::class); $discord->save($owner, 'fixture', (object) ['auto_notify' => true, 'webhook_url' => 'https://discord.com/api/webhooks/123/synthetic']);
        $discord->save($owner, 'fixture', (object) ['auto_notify' => false, 'webhook_url' => '']); self::assertTrue($discord->view($owner, 'fixture')['configured']);
        self::assertStringNotContainsString('synthetic', json_encode($discord->view($owner, 'fixture')));
        $job = $container->get(JobService::class)->submit('fixture', 'discord_send', str_repeat('a', 32), $owner, (object) ['message' => 'Own fixture message']);
        $handler = $container->get(ExecuteServerJobHandler::class); $handler(new ExecuteServerJob($job['id'])); $handler(new ExecuteServerJob($job['id']));
        $public = JobRepository::public($container->get(JobRepository::class)->get($job['id'])); self::assertSame('succeeded', $public['state']); self::assertSame('123456', $public['result']['remote_id']);
        self::assertStringNotContainsString('Own fixture message', json_encode($public));
        self::assertSame('sent', $container->get(Connection::class)->fetchOne('SELECT state FROM discord_deliveries'));
        $this->expectException(OutcomeUnknown::class); $discord->send($job['id'], 'Own fixture message', static function (): void {});
    }
    public function testUncertainDiscordPostIsNotReplayedAfterWorkerRestart(): void
    {
        $transport = $this->createMock(DiscordTransport::class); $transport->expects(self::once())->method('send')->willThrowException(new OutcomeUnknown('Synthetic timeout'));
        $this->boot(discord: $transport); $container = static::getContainer(); $owner = new Principal('local-owner', 'owner');
        $discord = $container->get(Discord::class); $discord->save($owner, 'fixture', (object) ['auto_notify' => false, 'webhook_url' => 'https://discord.com/api/webhooks/123/synthetic']);
        $job = $container->get(JobService::class)->submit('fixture', 'discord_send', str_repeat('a', 32), $owner, (object) ['message' => 'Own fixture']);
        $handler = $container->get(ExecuteServerJobHandler::class); $handler(new ExecuteServerJob($job['id'])); $handler(new ExecuteServerJob($job['id']));
        self::assertSame('uncertain', $container->get(Connection::class)->fetchOne('SELECT state FROM discord_deliveries'));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($job['id'])['state']);
    }
    public function testDiscordHttpTransportBoundsRedirectsMentionsAndResponse(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function ($method, $url, $options) use (&$calls): MockResponse {
            ++$calls; self::assertSame('POST', $method); self::assertSame('https://discord.com/api/webhooks/123/synthetic?wait=true', $url);
            self::assertSame(0, $options['max_redirects']); self::assertSame(12.0, (float) $options['max_duration']);
            self::assertSame([], json_decode($options['body'], true)['allowed_mentions']['parse']); return new MockResponse('{"id":"42"}');
        });
        self::assertSame('42', (new DiscordWebhook($client))->send('https://discord.com/api/webhooks/123/synthetic', 'Own fixture')); self::assertSame(1, $calls);
        foreach ([302, 429, 500] as $status) {
            try { (new DiscordWebhook(new MockHttpClient(new MockResponse('', ['http_code' => $status]))))->send('https://discord.com/api/webhooks/123/synthetic', 'Own fixture'); self::fail('Failed HTTP accepted'); }
            catch (\DomainException|OutcomeUnknown) { self::assertTrue(true); }
        }
        $this->expectException(OutcomeUnknown::class); (new DiscordWebhook(new MockHttpClient(new MockResponse(str_repeat('x', 17000)))))->send('https://discord.com/api/webhooks/123/synthetic', 'Own fixture');
    }
    public function testHttpReadsAreCachedAndAcceptingUpdateNeedsCurrentCsrf(): void
    {
        $steam = $this->createMock(SteamProvider::class); $steam->expects(self::never())->method('query');
        $network = $this->createMock(HostNetworkProvider::class); $network->expects(self::never())->method('collect');
        $client = $this->boot(steam: $steam, network: $network, http: true);
        foreach (['updates', 'connections', 'discord'] as $path) { $client->request('GET', '/api/'.$path.'?server=fixture'); self::assertResponseIsSuccessful(); }
        $client->request('POST', '/api/updates/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865'], content: '{"server_id":"fixture","request_id":"'.str_repeat('a',32).'"}'); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/session'); $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->request('POST', '/api/updates/check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token], content: '{"server_id":"fixture","request_id":"'.str_repeat('a',32).'"}'); self::assertResponseStatusCodeSame(202);
    }
    public function testRevokedPersonalGrantCannotReachAnyProviderFromAcceptedJob(): void
    {
        $this->boot(); $container = static::getContainer(); $owner = new Principal('local-owner', 'owner'); $access = $container->get(AccessStore::class);
        $access->configure($owner, ['enabled' => true, 'hostname' => 'fixture.private.ts.net']);
        $access->saveUser($owner, ['login' => 'relative@example.invalid', 'name' => 'Relative', 'role' => 'owner'], ['fixture']);
        $actor = $access->principal('relative@example.invalid');
        $job = $container->get(JobService::class)->submit('fixture', 'check_updates', str_repeat('a',32), $actor);
        $access->configure($owner, ['enabled' => false]);
        $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
        self::assertSame('interrupted', $container->get(JobRepository::class)->get($job['id'])['state']);
        self::assertFileDoesNotExist($this->directory.'/data/steam-updates.json');
    }
    public function testExternalAskaStarterKeepsItsOwnStartNotification(): void
    {
        $this->boot(); $container = static::getContainer(); $owner = new Principal('local-owner', 'owner');
        $container->get(Discord::class)->save($owner, 'fixture', (object) ['auto_notify' => true, 'webhook_url' => 'https://discord.com/api/webhooks/123/synthetic']);
        $directory = $this->directory.'/own-aska'; PrivateFiles::directory($directory);
        $container->get(InstanceRegistry::class)->register($owner, (object) ['id' => 'own-aska', 'game' => 'aska', 'label' => 'Own ASKA',
            'server_directory' => $directory, 'launcher_script' => $this->directory.'/own-starter.ps1']);
        $job = $container->get(JobService::class)->submit('own-aska', 'start', str_repeat('a',32));
        $container->get(\App\Job\WorkerClaim::class)->run($job, fn () => $container->get(\App\Integration\DiscordAfterAction::class)(new \App\Runtime\ServerActionCompleted('own-aska', 'start')));
        self::assertSame(0, (int) $container->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM discord_deliveries'));
    }
    public function testMetadataScheduleWaitsOneMinuteAndQueuesReadOnlyChecksEveryHalfHour(): void
    {
        $this->boot(); $container = static::getContainer(); $clock = new \Symfony\Component\Clock\MockClock('2026-10-16T12:00:00Z');
        $scheduler = new \App\Integration\MetadataScheduler($container->get(\App\Runtime\SliceRuntime::class), $container->get(RuntimeLifecycle::class),
            $container->get(InstanceRegistry::class), $container->get(JobService::class), $clock, $container->get(\App\Integration\DiscordStatusCard::class));
        self::assertSame(1, $scheduler->tick()); self::assertSame(0, $scheduler->tick());
        $clock->sleep(59); self::assertSame(0, $scheduler->tick()); $clock->sleep(1); self::assertSame(1, $scheduler->tick());
        $clock->sleep(1800); self::assertSame(2, $scheduler->tick());
        $operations = $container->get(Connection::class)->fetchFirstColumn('SELECT operation FROM slice_jobs ORDER BY sequence');
        self::assertSame(['refresh_connections', 'check_updates', 'refresh_connections', 'check_updates'], $operations);
        $container->get(RuntimeLifecycle::class)->close(); $this->expectException(\DomainException::class); $scheduler->tick();
    }
    public function testAutomaticDiscordMessagesFollowActualActionsAndDuplicateCommandsStayQuiet(): void
    {
        $running = false; $gateway = $this->createMock(ProcessGateway::class); $gateway->expects(self::exactly(6))->method('call')
            ->willReturnCallback(function ($id, $action) use (&$running): array {
                if ($action === 'Start') { $running = true; } elseif ($action === 'Stop') { $running = false; }
                return ['known' => true, 'running' => $running, 'starting' => false, 'launcher_running' => false, 'ready' => null,
                    'pid' => $running ? 42 : null, 'processes' => [], 'user_profile' => $this->directory.'/own-windows-user'];
            });
        $messages = []; $transport = $this->createMock(DiscordTransport::class); $transport->expects(self::exactly(2))->method('send')
            ->willReturnCallback(function ($url, $message) use (&$messages): string { $messages[] = $message; return '123456'; });
        $this->boot(discord: $transport, gateway: $gateway); $container = static::getContainer();
        $container->get(Discord::class)->save(new Principal('local-owner', 'owner'), 'fixture', (object) ['auto_notify' => true, 'webhook_url' => 'https://discord.com/api/webhooks/123/synthetic']);
        foreach (['start', 'start', 'stop', 'stop'] as $index => $operation) {
            $job = $container->get(JobService::class)->submit('fixture', $operation, str_repeat(dechex($index+1), 32));
            $container->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($job['id']));
            self::assertSame('succeeded', $container->get(JobRepository::class)->get($job['id'])['state']);
        }
        self::assertStringContainsString('Verbindungsbereitschaft im Serverlog prüfen', $messages[0]);
        self::assertStringContainsString('fixture.example.org:15637', $messages[0]); self::assertStringNotContainsString('synthetic-secret', $messages[0]);
        self::assertSame('Server gestoppt: Own Server', $messages[1]);
    }
    public function testFullProfileDoesNotStarveOthersOrRepeatAcceptedMetadataWork(): void
    {
        $this->boot(); $container = static::getContainer(); $clock = new \Symfony\Component\Clock\MockClock('2026-10-16T12:00:00Z');
        $owner = new Principal('local-owner', 'owner'); $second = $this->directory.'/own-second'; PrivateFiles::directory($second);
        $container->get(InstanceRegistry::class)->register($owner, (object) ['id' => 'second', 'game' => 'enshrouded', 'label' => 'Second', 'server_directory' => $second]);
        $scheduler = new \App\Integration\MetadataScheduler($container->get(\App\Runtime\SliceRuntime::class), $container->get(RuntimeLifecycle::class), $container->get(InstanceRegistry::class), $container->get(JobService::class), $clock, $container->get(\App\Integration\DiscordStatusCard::class));
        $jobs = $container->get(JobRepository::class); $service = $container->get(JobService::class);
        self::assertSame(1, $scheduler->tick()); foreach ($jobs->recent() as $job) { $jobs->transition($job['id'], 'succeeded', 'Own fixture'); }
        for ($index=0; $index<8; ++$index) { $service->submit('second', 'start', bin2hex(random_bytes(16))); }
        $clock->sleep(60); self::assertSame(1, $scheduler->tick()); self::assertSame(0, $scheduler->tick()); self::assertSame(0, $scheduler->tick());
        self::assertSame(1, (int) $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM slice_jobs WHERE operation='check_updates' AND server_id='fixture'"));
        foreach ($jobs->recent() as $job) { $jobs->transition($job['id'], 'succeeded', 'Own fixture'); }
        for ($index=0; $index<8; ++$index) { $service->submit('fixture', 'start', bin2hex(random_bytes(16))); }
        self::assertSame(1, $scheduler->tick()); self::assertSame(0, $scheduler->tick());
        self::assertSame(1, (int) $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM slice_jobs WHERE operation='check_updates' AND server_id='second'"));
    }
    public function testDiscordUsesUnloggedUntracedDedicatedClientInTheRealContainer(): void
    {
        static::bootKernel(); $transport = static::getContainer()->get(DiscordWebhook::class);
        $client = (new \ReflectionProperty($transport, 'client'))->getValue($transport);
        self::assertNotInstanceOf(\Symfony\Component\HttpClient\TraceableHttpClient::class, $client);
        self::assertNotInstanceOf(\Symfony\Component\HttpClient\RetryableHttpClient::class, $client);
        self::assertNull((new \ReflectionProperty($client, 'logger'))->getValue($client));
    }
    public function testTwoThousandUnicodeCharactersFitBothHttpSubmissionPaths(): void
    {
        $client = $this->boot(http: true); $client->request('GET', '/api/session'); $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token]; $message = str_repeat('🔥', 2000);
        $client->request('POST', '/api/discord/send', server: $headers, content: json_encode(['server_id' => 'fixture', 'request_id' => str_repeat('a',32), 'message' => $message])); self::assertResponseStatusCodeSame(202);
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode(['server_id' => 'fixture', 'request_id' => str_repeat('b',32), 'operation' => 'discord_send', 'payload' => ['message' => $message]])); self::assertResponseStatusCodeSame(202);
        self::assertSame(2, static::getContainer()->get(JobRepository::class)->pendingCount());
    }
}
