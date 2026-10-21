<?php
declare(strict_types=1);

namespace App\Tests;

use App\Job\JobRepository;
use App\Runtime\SliceRuntime;

final class HttpBoundaryTest extends SliceTestCase
{
    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'SERVER_PORT' => '18865',
            'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']);
        $this->ledger();
        return $client;
    }

    public function testAcceptedCommandsCarryAnIdAndRequireOriginAndCsrf(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $body = json_encode(['server_id' => 'demo-a', 'operation' => 'start', 'request_id' => str_repeat('a', 32)]);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865'], content: $body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('session_expired', json_decode($client->getResponse()->getContent(), true)['code']);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token], content: $body);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', json_decode($client->getResponse()->getContent(), true)['job']['state']);
    }

    public function testForeignHostAndFakeProxyIdentityDoNotGrantAccess(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/session', server: ['HTTP_HOST' => 'attacker.example:18865', 'HTTP_X_FORWARDED_HOST' => '127.0.0.1:18865', 'HTTP_TAILSCALE_USER_LOGIN' => 'fake-owner']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/session', server: ['REMOTE_ADDR' => '192.0.2.12']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReaderCannotPromoteItselfWithHeadersOrPayload(): void
    {
        $_ENV['SLICE_ROLE'] = $_SERVER['SLICE_ROLE'] = 'viewer'; putenv('SLICE_ROLE=viewer');
        $client = $this->client();
        $client->request('GET', '/api/session');
        $session = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('viewer', $session['role']);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865',
            'HTTP_X_MANAGER_TOKEN' => $session['token'], 'HTTP_X_QA_IDENTITY' => 'owner', 'HTTP_X_ROLE' => 'owner'],
            content: json_encode(['server_id' => 'demo-a', 'operation' => 'start', 'request_id' => str_repeat('a', 32), 'role' => 'owner']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], static::getContainer()->get(JobRepository::class)->recent());
    }

    public function testPersistentTokenSurvivesKernelRestartAndUnknownPathsAreRejected(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/session');
        $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token],
            content: json_encode(['server_id' => 'demo-a', 'operation' => 'start', 'request_id' => str_repeat('a', 32), 'executable' => 'D:\\SteamLibrary\\real.exe']));
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token],
            content: json_encode(['server_id' => 'enshrouded', 'operation' => 'start', 'request_id' => str_repeat('a', 32)]));
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token],
            content: json_encode(['server_id' => 'demo-a', 'operation' => 'stop', 'request_id' => str_repeat('a', 32)]));
        self::assertResponseStatusCodeSame(202);
    }

    public function testRuntimeDoesNotInitializeAnExistingUnrelatedDirectory(): void
    {
        $path = $this->directory.'/unrelated'; mkdir($path); file_put_contents($path.'/existing-world', 'preserve');
        try { (new SliceRuntime($path, 'simulated', 'owner'))->initialize(); self::fail('Existing directory accepted'); }
        catch (\RuntimeException) { self::assertSame('preserve', file_get_contents($path.'/existing-world')); }
        self::assertFileDoesNotExist($path.'/.indy-symfony-slice');
    }
}
