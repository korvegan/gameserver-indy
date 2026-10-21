<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Instance\InstanceRegistry;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** One small local history per instance; no collector, network or native process of its own. */
final class PerformanceHistory
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly InstanceRegistry $registry,
        private readonly ClockInterface $clock) {}
    private function path(string $id): string
    {
        $this->registry->get($id); return $this->runtime->directory.'/data/performance/'.$id.'.json';
    }
    private function read(string $id): array
    {
        $path = $this->path($id);
        return is_file($path) ? json_decode(PrivateFiles::read($path),true,flags:JSON_THROW_ON_ERROR) : ['samples'=>[],'markers'=>[],'previous'=>null];
    }
    private function change(string $id,callable $change): void
    {
        $folder = dirname($this->path($id)); PrivateFiles::directory($folder); PrivateFiles::directory($this->runtime->directory.'/locks');
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('performance:'.$id); $lock->acquire(true);
        try { $value = $change($this->read($id)); PrivateFiles::write($this->path($id),json_encode($value,JSON_THROW_ON_ERROR)); }
        finally { $lock->release(); }
    }
    public function record(string $id,array $status): void
    {
        $this->change($id,function(array $data) use ($status): array {
            $now = (float)$this->clock->now()->format('U.u'); $previous = $data['previous'];
            $processes = $status['processes'] ?? []; $process = count($processes)===1 ? (array)$processes[0] : null;
            $key = $process ? $process['pid'].':'.$process['started'] : null;
            $host = (array)($status['host_metrics'] ?? []); $count = $host['logical_processors'] ?? 0;
            $cpu = null; $io = null; $memory = $process['memory_bytes'] ?? null;
            if ($previous && $key!==null && $key===$previous['key'] && $now>$previous['at'] && $now-$previous['at']<=60) {
                $elapsed = $now-$previous['at'];
                if ($count>0 && isset($process['cpu_seconds'],$previous['cpu_seconds']) && $process['cpu_seconds']>=$previous['cpu_seconds']) {
                    $cpu = min(100,100*($process['cpu_seconds']-$previous['cpu_seconds'])/$elapsed/$count);
                }
                if (isset($process['io_bytes'],$previous['io_bytes']) && $process['io_bytes']>=$previous['io_bytes']) { $io=($process['io_bytes']-$previous['io_bytes'])/$elapsed; }
            }
            $state = ($status['known'] ?? false)!==true ? 'unknown' : (($status['running'] ?? null)===false ? 'stopped' : 'running');
            if ($state==='stopped') { $cpu=0; $io=0; $memory=0; }
            if ($state==='unknown') { $cpu=null; $io=null; $memory=null; }
            if ($state!=='running') { $key=null; }
            $sample = ['at'=>$now,'state'=>$state,'cpu_percent'=>$cpu,'memory_bytes'=>$memory,'io_bytes_sec'=>$io,
                'host_observed_at'=>$host['observed_at'] ?? null,
                'host_cpu_percent'=>$host['cpu_percent'] ?? null,'host_memory_used_bytes'=>$host['memory_used_bytes'] ?? null,
                'host_memory_total_bytes'=>$host['memory_total_bytes'] ?? null];
            $data['previous'] = $key===null ? null : ['at'=>$now,'key'=>$key,'cpu_seconds'=>$process['cpu_seconds'] ?? null,'io_bytes'=>$process['io_bytes'] ?? null];
            if (($data['state'] ?? null)!==$state || ($data['process_key'] ?? null)!==$key) {
                $data['markers'][]=['id'=>bin2hex(random_bytes(8)),'at'=>$now,'type'=>'process','label'=>['running'=>'Prozess läuft · beobachtet','stopped'=>'Gestoppt · beobachtet','unknown'=>'Prozessstatus unbekannt'][$state]];
            }
            $data['state']=$state; $data['process_key']=$key; $data['updated_at']=$now;
            $last = count($data['samples'])-1;
            if ($last>=0 && (int)($data['samples'][$last]['at']/60)===(int)($now/60)) { $data['samples'][$last]=$sample; }
            else { $data['samples'][]=$sample; }
            $data['samples']=array_slice(array_values(array_filter($data['samples'],fn($row)=>$row['at']>=$now-86400)),-1440);
            $data['markers']=array_slice(array_values(array_filter($data['markers'],fn($row)=>$row['at']>=$now-86400)),-200);
            return $data;
        });
    }
    public function mark(string $id,string $note): void
    {
        $note=trim($note); if (mb_strlen($note)>200) { throw new \InvalidArgumentException('Markierung maximal 200 Zeichen.'); }
        $this->change($id,function(array $data) use ($note): array {
            $now = (float)$this->clock->now()->format('U.u');
            $data['markers'][]=['id'=>bin2hex(random_bytes(8)),'at'=>$now,'type'=>'stutter','label'=>$note==='' ? 'Gerade ruckelt’s' : $note];
            $data['markers']=array_slice(array_values(array_filter($data['markers'],fn($row)=>$row['at']>=$now-86400)),-200);
            return $data;
        });
    }
    public function view(string $id): array
    {
        $data=$this->read($id); $now=(float)$this->clock->now()->format('U.u');
        return ['server_id'=>$id,'samples'=>$data['samples'],'markers'=>$data['markers'],'updated_at'=>$data['updated_at'] ?? null,
            'stale'=>!isset($data['updated_at']) || $now-$data['updated_at']>30,'retention_hours'=>24,'sample_interval_seconds'=>8];
    }
}
