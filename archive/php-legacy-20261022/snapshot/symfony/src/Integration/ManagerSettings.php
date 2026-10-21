<?php
declare(strict_types=1);
namespace App\Integration;

use App\Instance\InstanceRegistry;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Share the registry's metadata lock; retain unknown settings and credentials. */
final class ManagerSettings
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly SliceRuntime $runtime) {}
    public function read(): \stdClass { return $this->registry->settings(); }
    public function change(callable $change): mixed
    {
        $this->runtime->assertInitialized();
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('registry');
        $lock->acquire(true);
        try {
            $settings = $this->read(); $result = $change($settings); $this->registry->validate($settings);
            PrivateFiles::write($this->runtime->directory.'/manager.local.json', PrivateFiles::json($settings));
            return $result;
        } finally { $lock->release(); }
    }
}
