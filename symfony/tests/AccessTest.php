<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessPolicy;
use App\Access\AccessSnapshot;
use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessTest extends SliceTestCase
{
    private const HOST = 'family.test.ts.net';
    private const PORT = 18870;
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'SERVER_PORT' => '18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']);
        $this->ledger(); return $client;
    }
    private function store(): AccessStore { return static::getContainer()->get(AccessStore::class); }
    private function grant(string $role = 'family', ?array $servers = ['demo-a']): Principal
    {
        $store = $this->store(); $owner = new Principal('local-owner', 'owner');
        $store->configure($owner, ['enabled' => true, 'hostname' => self::HOST, 'port' => self::PORT]);
        $store->saveUser($owner, ['login' => $role.'@example.test', 'name' => 'Fixture '.$role,
            'role' => $role, 'server_ids' => $servers, 'enabled' => true], array_keys(SliceRuntime::SERVERS));
        return $store->principal($role.'@example.test');
    }
    private function remote(string $login = 'family@example.test'): array
    {
        return ['HTTP_HOST' => self::HOST, 'INDY_LISTENER_PORT' => (string) self::PORT, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_TAILSCALE_USER_LOGIN' => $login];
    }
    public function testLocalHeadersCannotSwitchIdentityAndPrivateListenerNeverFallsBack(): void
    {
        $client = $this->client(); $this->grant();
        $client->request('GET', '/api/session', server: ['HTTP_TAILSCALE_USER_LOGIN' => 'family@example.test', 'HTTP_X_ROLE' => 'viewer']);
        self::assertResponseIsSuccessful(); $local = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('local-owner', $local['principal']['id']); self::assertSame('owner', $local['role']);
        foreach ([['HTTP_HOST' => self::HOST], ['INDY_LISTENER_PORT' => (string) self::PORT],
            $this->remote('missing@example.test'), $this->remote() + ['HTTP_X_FORWARDED_PROTO' => 'https']] as $headers) {
            if (($headers['HTTP_TAILSCALE_USER_LOGIN'] ?? '') === 'family@example.test') { unset($headers['HTTP_TAILSCALE_USER_LOGIN']); }
            $client->request('GET', '/api/session', server: $headers); self::assertResponseStatusCodeSame(403);
        }
        $client->request('GET', '/api/session', server: $this->remote()); self::assertResponseIsSuccessful();
        $private = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('family', $private['role']); self::assertSame(['demo-a'], array_keys($private['servers']));
        self::assertNotSame($local['token'], $private['token']);
    }
    public function testFamilyScopesJobsAndRequiresPrivateOriginAndItsOwnToken(): void
    {
        $client = $this->client(); $this->grant();
        $client->request('GET', '/api/session'); $local = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->request('GET', '/api/session', server: $this->remote()); $token = json_decode($client->getResponse()->getContent(), true)['token'];
        foreach (['/api/status?server=demo-b', '/api/access'] as $path) {
            $client->request('GET', $path, server: $this->remote()); self::assertResponseStatusCodeSame(403);
        }
        $body = ['server_id' => 'demo-a', 'operation' => 'start', 'request_id' => str_repeat('a', 32)];
        foreach ([['origin' => 'http://'.self::HOST, 'token' => $token], ['origin' => 'https://'.self::HOST, 'token' => $local]] as $invalid) {
            $client->request('POST', '/api/jobs', server: $this->remote() + ['CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => $invalid['origin'], 'HTTP_X_MANAGER_TOKEN' => $invalid['token']], content: json_encode($body));
            self::assertResponseStatusCodeSame(403);
        }
        $headers = $this->remote() + ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://'.self::HOST, 'HTTP_X_MANAGER_TOKEN' => $token];
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode($body)); self::assertResponseStatusCodeSame(202);
        $accepted = json_decode($client->getResponse()->getContent(), true)['job']; self::assertArrayNotHasKey('actor', $accepted);
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode(array_replace($body,
            ['operation' => 'stop', 'request_id' => str_repeat('b', 32)]))); self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/jobs', server: $headers, content: json_encode(array_replace($body,
            ['server_id' => 'demo-b', 'request_id' => str_repeat('c', 32)]))); self::assertResponseStatusCodeSame(403);
        $other = static::getContainer()->get(JobService::class)->submit('demo-b', 'start', str_repeat('d', 32));
        $client->request('GET', '/api/jobs?id='.$other['id'], server: $this->remote()); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/jobs', server: $this->remote()); self::assertResponseIsSuccessful();
        self::assertSame([$accepted['id']], array_column(json_decode($client->getResponse()->getContent(), true)['jobs'], 'id'));
    }
    public function testHttpHeadersCannotCreateTheProtectedListenerVariable(): void
    {
        $client = $this->client(); $this->grant();
        $client->request('GET', '/api/session', server: ['INDY_LISTENER_PORT' => '',
            'HTTP_INDY_LISTENER_PORT' => '18865', 'HTTP_X_FORWARDED_PORT' => '18865']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/session', server: $this->remote() + ['HTTP_INDY_LISTENER_PORT' => '18865',
            'HTTP_X_FORWARDED_HOST' => '127.0.0.1:18865', 'HTTP_X_ROLE' => 'owner']);
        self::assertResponseIsSuccessful();
        self::assertSame('family', json_decode($client->getResponse()->getContent(), true)['role']);
    }
    public function testConfigurationKeepsStrictTypesAndLegacyUnicodeIdentityNormalization(): void
    {
        $this->client();
        foreach (['enabled', 'hostname', 'port'] as $field) {
            try { AccessStore::configuration([$field => null]); self::fail('Null setting accepted'); }
            catch (\InvalidArgumentException) { self::assertFileDoesNotExist($this->directory.'/access-settings.json'); }
        }
        self::assertSame('ss@example.test', AccessStore::login(' ß@EXAMPLE.TEST '));
        self::assertSame('ss@example.test', AccessStore::login('=?UTF-8?B?'.base64_encode('ß@example.test').'?=', true));
    }
    public function testRevocationAndRoleChangeInvalidateOldClaimsAndQueuedWork(): void
    {
        $client = $this->client(); $principal = $this->grant('owner', null); $store = $this->store();
        $jobs = static::getContainer()->get(JobRepository::class); $service = static::getContainer()->get(JobService::class);
        $first = $service->submit('demo-a', 'start', str_repeat('a', 32), $principal);
        $second = $service->submit('demo-a', 'stop', str_repeat('b', 32), $principal);
        $store->saveUser(new Principal('local-owner', 'owner'), ['id' => $principal->id, 'revision' => $principal->revision,
            'login' => 'owner@example.test', 'name' => 'Fixture owner', 'role' => 'viewer', 'server_ids' => ['demo-a'], 'enabled' => true], ['demo-a', 'demo-b']);
        $control = $this->createMock(ServerControl::class); $control->expects(self::never())->method('execute');
        $handler = new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control,
            static::getContainer()->get(AccessPolicy::class));
        $handler(new ExecuteServerJob($first['id']));
        self::assertSame('interrupted', $jobs->get($first['id'])['state']);
        self::assertSame('interrupted', $jobs->get($second['id'])['state']);
        $this->expectException(AccessDeniedException::class); static::getContainer()->get(AccessPolicy::class)->require($principal, 'server.start', 'demo-a');
    }
    public function testGrantCompareAndSwapKeepsNewerRightsAndRemoteOwnerIsNotLocalPersonalOwner(): void
    {
        $this->client(); $principal = $this->grant('owner', null); self::assertFalse($principal->localOwner());
        self::assertTrue($principal->allows('server.stop', 'demo-b')); $store = $this->store();
        $body = ['id' => $principal->id, 'revision' => 1, 'login' => 'owner@example.test', 'name' => 'Owner',
            'role' => 'owner', 'server_ids' => null, 'enabled' => false];
        $store->saveUser(new Principal('local-owner', 'owner'), $body, ['demo-a', 'demo-b']);
        try { $store->saveUser(new Principal('local-owner', 'owner'), $body, ['demo-a', 'demo-b']); self::fail('Stale grant accepted'); }
        catch (\DomainException) { self::assertFalse($store->view()['users'][0]['enabled']); }
        self::assertSame(2, $store->view()['users'][0]['revision']);
        try { $store->configure($principal, ['enabled' => false]); self::fail('Revoked owner changed settings'); }
        catch (AccessDeniedException) { self::assertTrue($store->settings()['enabled']); }
    }
    public function testGlobalDisableInterruptsPreviouslyAcceptedPrivateCommands(): void
    {
        $this->client(); $principal = $this->grant();
        $jobs = static::getContainer()->get(JobRepository::class);
        $job = static::getContainer()->get(JobService::class)->submit('demo-a', 'start', str_repeat('a', 32), $principal);
        $this->store()->configure(new Principal('local-owner', 'owner'), ['enabled' => false, 'hostname' => self::HOST, 'port' => self::PORT]);
        $control = $this->createMock(ServerControl::class); $control->expects(self::never())->method('execute');
        (new ExecuteServerJobHandler($jobs, static::getContainer()->get(SliceRuntime::class), $control,
            static::getContainer()->get(AccessPolicy::class)))(new ExecuteServerJob($job['id']));
        self::assertSame('interrupted', $jobs->get($job['id'])['state']);
        static::getContainer()->get(AccessPolicy::class)->require(new Principal('local-owner', 'owner'), 'server.start', 'demo-a');
    }
    public function testLegacyHmacVectorsAndKeyAreStableAndBadKeyIsNotReplaced(): void
    {
        $this->client(); file_put_contents($this->directory.'/session-key', str_repeat('a', 43));
        $keys = static::getContainer()->get(SessionKeys::class);
        // Computed with the legacy Python HMAC contract and a public synthetic key.
        self::assertSame('83a8167c54d518fc7120fab52533361b26e462445f707953fde6dcf6113e2203', $keys->token(new Principal('local-owner', 'owner'), 'local-owner'));
        self::assertSame('669230f1c689925b29f6117ce326b2a848048c117eaa9ccc08f13937d292a87d', $keys->token(new Principal('user-legacy', 'owner', null, 7), 'family:'.self::HOST));
        file_put_contents($this->directory.'/session-key', 'broken');
        try { $keys->token(new Principal('local-owner', 'owner'), 'local-owner'); self::fail('Bad key accepted'); }
        catch (\RuntimeException) { self::assertSame('broken', file_get_contents($this->directory.'/session-key')); }
    }
    public function testSnapshotPreservesIdsRolesRevisionsAuditAndKeyOutsideChronicle(): void
    {
        $this->client(); $principal = $this->grant('owner', null);
        static::getContainer()->get(SessionKeys::class)->token(new Principal('local-owner', 'owner'), 'local-owner');
        $before = hash_file('sha256', $this->directory.'/access.sqlite3');
        $package = $this->directory.'/package';
        $manifest = (new AccessSnapshot())->copy($this->directory, $package);
        self::assertSame(1, $manifest['users']); self::assertSame(1, $manifest['schema_version']);
        self::assertSame($before, hash_file('sha256', $this->directory.'/access.sqlite3'));
        self::assertSame(file_get_contents($this->directory.'/session-key'), file_get_contents($package.'/session-key'));
        self::assertSame(file_get_contents($this->directory.'/access-settings.json'), file_get_contents($package.'/access-settings.json'));
        $copy = new \PDO('sqlite:'.$package.'/access.sqlite3'); $row = $copy->query('SELECT id,role,revision FROM users')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['id' => $principal->id, 'role' => 'owner', 'revision' => 1], $row);
        self::assertSame(2, (int) $copy->query('SELECT COUNT(*) FROM audit')->fetchColumn()); $copy = null;
        self::assertFileDoesNotExist($package.'/slice.sqlite');
        foreach ($manifest['hashes'] as $file => $hash) { self::assertSame($hash, hash_file('sha256', $package.'/'.$file)); }
        try { (new AccessSnapshot())->copy($this->directory, $package); self::fail('Existing package overwritten'); }
        catch (\DomainException) { self::assertFileExists($package.'/.indy-access-import.json'); }
    }
    public function testUnknownAccessSchemaIsNotImportedOrChanged(): void
    {
        $this->client(); $this->grant(); file_put_contents($this->directory.'/session-key', str_repeat('a', 43));
        $db = new \PDO('sqlite:'.$this->directory.'/access.sqlite3'); $db->exec('PRAGMA user_version=99'); $db = null;
        $before = hash_file('sha256', $this->directory.'/access.sqlite3');
        try { (new AccessSnapshot())->copy($this->directory, $this->directory.'/rejected'); self::fail('Future schema accepted'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($this->directory.'/rejected'); }
        self::assertSame($before, hash_file('sha256', $this->directory.'/access.sqlite3'));
    }
}
