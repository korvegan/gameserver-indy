<?php
declare(strict_types=1);

namespace App\Tests;

use App\Job\JobRepository;
use App\Runtime\SliceRuntime;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class SliceTestCase extends WebTestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/indy-symfony-test-'.bin2hex(random_bytes(8));
        foreach (['SLICE_STATE_DIR' => $this->directory, 'SLICE_DRIVER' => 'simulated', 'SLICE_ROLE' => 'owner'] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value; putenv($key.'='.$value);
        }
        (new SliceRuntime($this->directory, 'simulated', 'owner'))->initialize();
    }

    protected function ledger(): JobRepository
    {
        $repository = static::getContainer()->get(JobRepository::class);
        static::getContainer()->get(\App\Access\AccessStore::class)->initialize();
        $repository->initialize();
        static::getContainer()->get('messenger.transport.async')->setup();
        static::getContainer()->get('messenger.transport.metadata')->setup();
        static::getContainer()->get(\App\Integration\Discord::class)->initialize();
        return $repository;
    }
    protected function handler(\App\Runtime\ServerControl $control): \App\Job\ExecuteServerJobHandler
    {
        $container = static::getContainer();
        return new \App\Job\ExecuteServerJobHandler($container->get(JobRepository::class), $container->get(SliceRuntime::class), $control,
            $container->get(\App\Access\AccessPolicy::class), $container->get(\App\Runtime\RuntimeLifecycle::class),
            $container->get(\App\Job\WorkerClaim::class), $container->get(\App\Instance\InstanceConfiguration::class),
            $container->get(\App\Backup\WorldBackups::class));
    }

    protected function tearDown(): void
    {
        if (static::$kernel !== null) { static::getContainer()->get(\Doctrine\DBAL\Connection::class)->close(); }
        parent::tearDown();
        $this->remove($this->directory);
        foreach (['SLICE_STATE_DIR', 'SLICE_DRIVER', 'SLICE_ROLE'] as $key) { unset($_ENV[$key], $_SERVER[$key]); putenv($key); }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') $this->remove($path.'/'.$name); }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) { unlink($path); }
    }
}
