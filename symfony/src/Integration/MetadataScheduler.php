<?php
declare(strict_types=1);
namespace App\Integration;

use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Job\JobService;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Explicit supervisor tick. HTTP reads never start schedulers or network requests. */
final class MetadataScheduler
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly RuntimeLifecycle $lifecycle,
        private readonly InstanceRegistry $registry, private readonly JobService $jobs, private readonly ClockInterface $clock) {}
    public function tick(): int
    {
        $epoch = $this->lifecycle->epoch(); $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('metadata-scheduler');
        if (!$lock->acquire()) { return 0; }
        try {
            $path = $this->runtime->directory.'/data/metadata-schedule.json'; $now = $this->clock->now()->getTimestamp();
            try { $state = PrivateFiles::object(PrivateFiles::read($path, 16384)); } catch (\Throwable) { $state = new \stdClass(); }
            if (($state->epoch ?? null) !== $epoch) { $state = (object) ['epoch' => $epoch, 'boot_at' => $now, 'network' => 0, 'steam' => 0]; }
            $profiles = $this->registry->settings(); $count = 0; $actor = new Principal('local-owner', 'owner');
            if ($profiles->servers && ($now - ($state->network ?? 0) >= Connections::MAX_AGE || ($state->network ?? 0) > $now)) {
                $this->jobs->submit($profiles->servers[0]->id, 'refresh_connections', bin2hex(random_bytes(16)), $actor); $state->network = $now; ++$count;
            }
            if (($profiles->automatic_update_checks ?? true) === true && $now - $state->boot_at >= 60
                && ($now - ($state->steam ?? 0) >= SteamUpdates::INTERVAL || ($state->steam ?? 0) > $now)) {
                foreach ($profiles->servers as $profile) { $this->jobs->submit($profile->id, 'check_updates', bin2hex(random_bytes(16)), $actor); ++$count; }
                $state->steam = $now;
            }
            PrivateFiles::directory(dirname($path)); PrivateFiles::write($path, PrivateFiles::json($state)); return $count;
        } finally { $lock->release(); }
    }
}
