<?php
declare(strict_types=1);
namespace App\Instance;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Job\JobRepository;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Uses the same acceptance and execution locks as lifecycle jobs. */
final class InstanceMutation
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs,
        private readonly ServerControl $control, private readonly AccessPolicy $policy) {}
    public function stopped(Principal $actor, string $id, string $permission, callable $change): mixed
    {
        $this->policy->require($actor, $permission, $id); $this->runtime->assertInitialized();
        $factory = new LockFactory(new FlockStore($this->runtime->directory.'/locks'));
        $acceptance = $factory->createLock('acceptance'); $execution = $factory->createLock('execute:'.$id);
        if (!$acceptance->acquire()) { throw new \DomainException('Eine Auftragsannahme läuft. Bitte erneut versuchen.'); }
        try {
            if (!$execution->acquire()) { throw new \DomainException('Dieses Serverprofil arbeitet bereits.'); }
            try {
                $this->policy->require($actor, $permission, $id);
                if ($this->jobs->pendingCount($id) !== 0) { throw new \DomainException('Für dieses Profil stehen noch Aufträge aus.'); }
                $pending = $this->runtime->directory.'/data/world-backups/'.$id.'/restore-pending.json';
                if (file_exists($pending) || is_link($pending)) { throw new \DomainException('Eine Weltwiederherstellung benötigt zuerst die ausdrückliche Rückkehr zum gesicherten Stand.'); }
                try { $status = $this->control->status($id); }
                catch (\InvalidArgumentException) { throw new \DomainException('Für dieses Profil ist der native Prozessadapter noch nicht aktiviert.'); }
                if (($status['running'] ?? null) !== false || ($status['starting'] ?? null) !== false
                    || ($status['launcher_running'] ?? null) !== false) { throw new \DomainException('Server und Starter müssen eindeutig gestoppt sein.'); }
                return $change($status);
            } finally { $execution->release(); }
        } finally { $acceptance->release(); }
    }
}
