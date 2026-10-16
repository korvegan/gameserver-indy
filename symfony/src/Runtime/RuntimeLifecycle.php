<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Job\JobRepository;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** The supervisor owns the lifetime lease; workers only consume the current epoch. */
final class RuntimeLifecycle
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs) {}
    public function epoch(): string
    {
        $this->runtime->assertInitialized();
        $path = $this->runtime->directory.'/runtime-lifecycle.json';
        if (!file_exists($path)) {
            if ($this->runtime->driver !== 'managed') { return 'isolated'; }
            throw new \DomainException('Die native Verwaltung wurde noch nicht als aktiver Lauf geöffnet.');
        }
        $value = PrivateFiles::object(PrivateFiles::read($path, 4096));
        if (($value->active ?? null) !== true || !is_string($value->epoch ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $value->epoch)) {
            throw new \DomainException('Die Verwaltung wird beendet. Dieser Befehl wurde nicht ausgeführt.');
        }
        return $value->epoch;
    }
    public function requireClaim(array $job): void
    {
        try { $current = $this->epoch(); }
        catch (\DomainException $error) { throw new OutcomeUnknown($error->getMessage(), previous: $error); }
        if (($job['runtime_epoch'] ?? 'isolated') !== $current) { throw new OutcomeUnknown('Der Auftrag gehört zu einem früheren Verwaltungslauf und wurde nicht erneut ausgeführt.'); }
    }
    public function open(): string
    {
        $this->runtime->assertInitialized(); $factory = new LockFactory(new FlockStore($this->runtime->directory.'/locks'));
        $acceptance = $factory->createLock('acceptance'); $acceptance->acquire(true); $workers = [];
        try {
            foreach ($this->jobs->pendingTargets() as $id) {
                $lock = $factory->createLock('execute:'.$id);
                if (!$lock->acquire()) { throw new \DomainException('Ein vorheriger Worker arbeitet noch. Kein zweiter Verwaltungslauf gestartet.'); }
                $workers[] = $lock;
            }
            $this->jobs->interruptQueued('Verwaltung neu gestartet. Wartender Befehl wurde nicht automatisch erneut ausgeführt.');
            foreach ($this->jobs->pendingTargets() as $id) { if ($this->jobs->hasRunning($id)) { $this->jobs->recover($id); } }
            $epoch = bin2hex(random_bytes(16));
            PrivateFiles::write($this->runtime->directory.'/runtime-lifecycle.json', PrivateFiles::json((object) ['active' => true, 'epoch' => $epoch]));
            return $epoch;
        } finally { foreach ($workers as $lock) { $lock->release(); } $acceptance->release(); }
    }
    public function close(): void
    {
        $this->runtime->assertInitialized();
        $acceptance = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('acceptance'); $acceptance->acquire(true);
        try {
            PrivateFiles::write($this->runtime->directory.'/runtime-lifecycle.json', PrivateFiles::json((object) ['active' => false, 'epoch' => bin2hex(random_bytes(16))]));
            $this->jobs->interruptQueued('Verwaltung wird beendet. Wartender Befehl wurde nicht ausgeführt.');
        } finally { $acceptance->release(); }
    }
}
