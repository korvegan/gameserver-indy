<?php
declare(strict_types=1);
namespace App\Runtime;
use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** One optional plan per target. Unknown occupancy never authorizes an action. */
final class MaintenancePlans
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly InstanceRegistry $registry,private readonly AccessPolicy $policy,
        private readonly ProcessObservation $observation,private readonly RuntimeLifecycle $lifecycle,private readonly JobService $jobs,
        private readonly JobRepository $repository,private readonly ClockInterface $clock) {}
    private function path(string $id): string { $this->registry->get($id); return $this->runtime->directory.'/data/maintenance/'.$id.'.json'; }
    private function read(string $id): ?array { return is_file($this->path($id)) ? json_decode(PrivateFiles::read($this->path($id)),true,flags:JSON_THROW_ON_ERROR) : null; }
    private function change(string $id,callable $callback): mixed
    {
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('maintenance:'.$id); $lock->acquire(true);
        try { $plan=$this->read($id); $result=$callback($plan); if ($plan!==null) { PrivateFiles::directory(dirname($this->path($id))); PrivateFiles::write($this->path($id),json_encode($plan,JSON_THROW_ON_ERROR)); } return $result; }
        finally { $lock->release(); }
    }
    public function view(Principal $actor,string $id): array
    {
        $this->policy->require($actor,'status.read',$id); $status=$this->observation->view($id); $stopped=($status['known'] ?? false) && ($status['running'] ?? null)===false && ($status['starting'] ?? null)===false && ($status['launcher_running'] ?? null)===false;
        $plan=$this->read($id);
        if ($plan) {
            unset($plan['actor'],$plan['runtime_epoch']);
            if (isset($plan['job_id'])) { $job=$this->repository->get($plan['job_id']); $plan['job_state']=$job['state']; $plan['message']=$job['message']; }
        }
        return ['players'=>['count'=>$stopped ? 0 : null,'state'=>$stopped ? 'current' : 'unknown',
            'source'=>$stopped ? 'Nativer Prozessstatus: Server und Starter gestoppt' : 'Noch keine mit echten Spielbeitritten geprüfte Zählquelle',
            'observed_at'=>$status['observed_at'] ?? null],'plan'=>$plan];
    }
    public function save(Principal $actor,string $id,\stdClass $body): array
    {
        $this->policy->require($actor,'server.restart',$id);
        return $this->change($id,function(?array &$plan) use ($actor,$body): array {
            $action=$body->action ?? '';
            if ($action==='create') {
                if (array_diff(array_keys(get_object_vars($body)),['action','operation','delay']) || !in_array($body->operation ?? null,['stop','restart'],true)
                    || !is_int($body->delay ?? null) || $body->delay<60 || $body->delay>3600) { throw new \InvalidArgumentException('Stopp/Neustart und Vorlauf von 60 bis 3600 Sekunden wählen.'); }
                if ($plan && in_array($plan['state'],['waiting_approval','countdown'],true)) { throw new \DomainException('Bestehenden Wartungsplan zuerst freigeben oder abbrechen.'); }
                if (isset($plan['job_id']) && in_array($this->repository->get($plan['job_id'])['state'],['queued','running'],true)) { throw new \DomainException('Wartungsauftrag wird bereits ausgeführt. Ergebnis abwarten.'); }
                $plan=['id'=>bin2hex(random_bytes(16)),'operation'=>$body->operation,'delay'=>$body->delay,'state'=>'waiting_approval',
                    'actor'=>$actor->claim(),'runtime_epoch'=>$this->lifecycle->epoch(),'created_at'=>$this->clock->now()->getTimestamp(),'execute_at'=>null];
            } else {
                if (array_diff(array_keys(get_object_vars($body)),['action','id']) || !$plan || ($body->id ?? null)!==$plan['id']
                    || !in_array($action,['approve','cancel'],true) || !in_array($plan['state'],['waiting_approval','countdown'],true)) { throw new \DomainException('Aktuellen, noch nicht angenommenen Wartungsplan wählen.'); }
                if ($action==='cancel') { $plan['state']='cancelled'; }
                else {
                    if ($plan['state']!=='waiting_approval') { throw new \DomainException('Vorlauf läuft bereits. Bei Bedarf abbrechen.'); }
                    $plan['state']='countdown'; $plan['actor']=$actor->claim(); $plan['runtime_epoch']=$this->lifecycle->epoch();
                    $plan['approved_at']=$this->clock->now()->getTimestamp(); $plan['execute_at']=$plan['approved_at']+$plan['delay'];
                }
            }
            return ['message'=>$action==='create' ? 'Wartungsplan wartet auf ausdrückliche Besitzerfreigabe. Unbekannte Belegung zählt nicht als leer.' : ($action==='cancel' ? 'Wartung vor Auftragsannahme abgebrochen.' : 'Wartung ausdrücklich freigegeben. Der angekündigte Vorlauf läuft.')];
        });
    }
    public function tick(): void
    {
        foreach ($this->registry->settings()->servers as $profile) {
            if (!is_file($this->path($profile->id))) continue;
            try { $this->change($profile->id,function(?array &$plan) use ($profile): void {
                if (!$plan || !in_array($plan['state'],['waiting_approval','countdown'],true)) return;
                if ($plan['runtime_epoch']!==$this->lifecycle->epoch()) { $plan['state']='cancelled'; $plan['message']='Verwaltung neu gestartet; Wartung neu beauftragen.'; return; }
                $actor=Principal::fromClaim($plan['actor']);
                try { $this->policy->require($actor,'server.restart',$profile->id); }
                catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) { $plan['state']='cancelled'; $plan['message']='Freigabe geändert; keine Wartung ausgeführt.'; return; }
                if ($plan['state']!=='countdown' || $this->clock->now()->getTimestamp()<$plan['execute_at']) return;
                // Durable request ID makes a retry after a failed plan-file write harmless.
                $job=$this->jobs->submit($profile->id,$plan['operation'],$plan['id'],$actor); $plan['job_id']=$job['id']; $plan['state']='queued';
            }); } catch (\DomainException) { /* Busy target stays pending; other profiles continue. */ }
        }
    }
}
