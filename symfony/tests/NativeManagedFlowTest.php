<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Job\ExecuteServerJob;
use App\Job\ExecuteServerJobHandler;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\NativePowerShell;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;
use Symfony\Component\Filesystem\Filesystem;

final class NativeManagedFlowTest extends SliceTestCase
{
    public function testRegisteredNativeProfilesCanonicalIdentityExternalStarterAndMeasuredSharedStatus(): void
    {
        if (getenv('SLICE_WINDOWS_TEST') !== '1') { self::markTestSkipped('Native eigene Profilstellvertreter ausdrücklich aktivieren.'); }
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        static::bootKernel(); $jobs = $this->ledger(); $container = static::getContainer();
        $shell = $container->get(NativePowerShell::class); $paths = $container->get(NativePaths::class);
        $prepared = $shell->run(dirname(__DIR__).'/tools/prepare-managed-fixtures.ps1', ['-StateDirectory', $shell->windowsPath($this->directory)], 45);
        self::assertTrue($prepared->isSuccessful(), 'Own native fixtures compile');
        $manifest = PrivateFiles::object(PrivateFiles::read($this->directory.'/native/managed-fixtures.json'));
        $root = $paths->local($manifest->root); self::assertStringContainsString('indy-managed-fixture-', basename($root));
        $registry = $container->get(InstanceRegistry::class); $games = $container->get(GameCatalog::class);
        $gateway = $container->get(ProcessGateway::class); $lifecycle = $container->get(RuntimeLifecycle::class);
        $service = $container->get(JobService::class); $handler = $container->get(ExecuteServerJobHandler::class);
        $observation = $container->get(ProcessObservation::class); $registered = [];
        try {
            foreach ($manifest->fixtures as $fixture) {
                self::assertSame($fixture->sha256, hash_file('sha256', $paths->local($fixture->executable)), 'Compiler proof before every registration');
                $profile = $fixture->profile; $registry->register(new Principal('local-owner', 'owner'), $profile); $registered[] = $fixture;
                $config = $games->adapter($profile->game)->initial();
                if ($profile->game === 'aska') { $config->{'authentication token'} = 'synthetic-native-fixture'; }
                PrivateFiles::write($registry->configPath($profile), $games->adapter($profile->game)->encode($config, ''));
            }
            $lifecycle->open(); $request = 0;
            $execute = function (string $id, string $operation) use (&$request, $service, $handler, $jobs): array {
                $job = $service->submit($id, $operation, str_pad(dechex(++$request), 32, '0', STR_PAD_LEFT));
                $handler(new ExecuteServerJob($job['id'])); $result = $jobs->get($job['id']);
                self::assertSame('succeeded', $result['state'], $operation.' of own '.$id.': '.$result['message']);
                return $result;
            };
            $execute('fixture-a', 'start'); $execute('fixture-b', 'start');
            $a = $gateway->call('fixture-a', 'Status'); $b = $gateway->call('fixture-b', 'Status');
            self::assertTrue($a['running']); self::assertTrue($b['running']); self::assertNotSame($a['pid'], $b['pid']);
            self::assertNull($a['ready'], 'A process marker is not game readiness');
            self::assertNotEmpty($a['processes'][0]['started']);
            $execute('fixture-a', 'stop');
            self::assertFalse($gateway->call('fixture-a', 'Status')['running']);
            self::assertSame($b['pid'], $gateway->call('fixture-b', 'Status')['pid'], 'Same executable name in another installation survives');
            $firstDirectory = $paths->local($manifest->fixtures[0]->profile->server_directory);
            self::assertSame((string) $a['pid'], trim(PrivateFiles::read($firstDirectory.'/graceful-stop.txt')));
            $execute('fixture-aska', 'start'); $aska = $gateway->call('fixture-aska', 'Status');
            self::assertTrue($aska['running']); self::assertTrue($aska['launcher_running']);
            self::assertNull($aska['ready']); $execute('fixture-aska', 'stop');
            self::assertFalse($gateway->call('fixture-aska', 'Status')['launcher_running']);
            $samples = [];
            for ($i = 0; $i < 3; ++$i) { $started = hrtime(true); $gateway->call('fixture-b', 'Status'); $samples[] = (hrtime(true) - $started) / 1000000; }
            $observation->refresh('fixture-b'); $started = hrtime(true);
            for ($i = 0; $i < 100; ++$i) { self::assertSame($b['pid'], $observation->view('fixture-b')['pid']); }
            $cache = (hrtime(true) - $started) / 1000000 / 100; sort($samples);
            fwrite(STDOUT, sprintf("\nOwn native profile status median %.1f ms; shared snapshot mean %.3f ms (100 reads).\n", $samples[1], $cache));
            $epoch = $lifecycle->epoch(); $lifecycle->close();
            foreach ([['fixture-a', 'Start'], ['fixture-b', 'Stop']] as [$id, $action]) {
                $fixture = array_values(array_filter($manifest->fixtures, fn ($item) => $item->profile->id === $id))[0];
                $refused = $shell->run(dirname(__DIR__, 2).'/scripts/windows-server.ps1', ['-Action', $action,
                    '-ServerDirectory', $fixture->profile->server_directory, '-ExecutableName', 'enshrouded_server.exe',
                    '-RuntimeFencePath', $shell->windowsPath($this->directory.'/runtime-lifecycle.json'), '-RuntimeEpoch', $epoch], 20);
                self::assertFalse($refused->isSuccessful(), 'Closed epoch blocks the native '.$action.' before its side effect');
            }
            self::assertFalse($gateway->call('fixture-a', 'Status')['running']);
            self::assertSame($b['pid'], $gateway->call('fixture-b', 'Status')['pid']);
        } finally {
            foreach ($registered as $fixture) {
                if (hash_file('sha256', $paths->local($fixture->executable)) !== $fixture->sha256) { throw new \RuntimeException('Own fixture changed; no cleanup action performed.'); }
                // Only these hash-proven disposable binaries; never a real installation.
                $stopped = $shell->run(dirname(__DIR__, 2).'/scripts/windows-server.ps1', ['-Action', 'Stop',
                    '-ServerDirectory', $fixture->profile->server_directory, '-ExecutableName', basename($paths->local($fixture->executable))], 70);
                self::assertTrue($stopped->isSuccessful(), 'Own native fixture cleanup');
            }
            $lifecycle->close(); (new Filesystem())->remove($root);
        }
    }
}
