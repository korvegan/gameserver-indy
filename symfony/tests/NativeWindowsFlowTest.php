<?php
declare(strict_types=1);

namespace App\Tests;

use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Runtime\WindowsStandinControl;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class NativeWindowsFlowTest extends SliceTestCase
{
    public function testHttpQueueWorkerNativeProcessAndGracefulStop(): void
    {
        if (getenv('SLICE_WINDOWS_TEST') !== '1') { self::markTestSkipped('Windows-Standin explizit aktivieren.'); }
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'windows'; putenv('SLICE_DRIVER=windows');
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'SERVER_PORT' => '18865', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->ledger();
        $control = static::getContainer()->get(WindowsStandinControl::class);
        $control->prepare();
        try {
            $client->request('GET', '/api/session');
            $token = json_decode($client->getResponse()->getContent(), true)['token'];
            $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token],
                content: json_encode(['server_id' => 'demo-a', 'operation' => 'start', 'request_id' => str_repeat('a', 32)]));
            self::assertResponseStatusCodeSame(202);
            $first = json_decode($client->getResponse()->getContent(), true)['job'];
            $this->consume();
            $started = $control->status('demo-a');
            self::assertTrue($started['running']);
            self::assertTrue($started['ready']);
            self::assertFalse($control->status('demo-b')['running'], 'Other installation must not adopt this executable');
            static::getContainer()->get(ExecuteServerJobHandler::class)(new ExecuteServerJob($first['id']));
            self::assertSame($started['pid'], $control->status('demo-a')['pid'], 'Duplicate delivery cannot start another process');
            $client->request('POST', '/api/jobs', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865', 'HTTP_X_MANAGER_TOKEN' => $token],
                content: json_encode(['server_id' => 'demo-a', 'operation' => 'stop', 'request_id' => str_repeat('b', 32)]));
            self::assertResponseStatusCodeSame(202);
            $stopped = json_decode($client->getResponse()->getContent(), true)['job'];
            $this->consume();
            self::assertFalse($control->status('demo-a')['running']);
            self::assertSame((string) $started['pid'], file_get_contents($this->directory.'/native/demo-a/graceful-stop.txt'));
            $client->request('GET', '/api/jobs?id='.$stopped['id']);
            self::assertSame('succeeded', json_decode($client->getResponse()->getContent(), true)['job']['state']);
        } finally {
            foreach (['demo-a', 'demo-b'] as $id) { $control->execute($id, 'stop'); }
        }
    }

    private function consume(): void
    {
        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('messenger:consume'));
        self::assertSame(0, $command->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10, '--sleep' => 0]));
    }
}
