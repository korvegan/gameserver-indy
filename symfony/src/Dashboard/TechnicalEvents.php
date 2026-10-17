<?php
declare(strict_types=1);
namespace App\Dashboard;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Log\LogText;

final class TechnicalEvents
{
    public function __construct(private readonly InstanceRegistry $registry,private readonly JobRepository $jobs,
        private readonly \App\Game\GameCatalog $games,private readonly \App\Integration\ManagerSettings $settings,
        private readonly \App\Runtime\RuntimeEvents $runtimeEvents) {}
    public function read(string $id): array
    {
        $path = $this->registry->dataPath($id).'/events.jsonl'; $events = []; $values = null;
        try {
            $profile = $this->registry->get($id); $config = $this->games->adapter($profile->game)->decode(\App\Storage\PrivateFiles::read($this->registry->configPath($profile)));
            $webhook = getenv('DISCORD_WEBHOOK_URL') ?: ($this->settings->read()->discord->webhook_url ?? ''); $values = LogText::secrets($config,is_string($webhook) ? $webhook : '');
        } catch (\Throwable) {}
        if ($values !== null && is_file($path) && ($file = @fopen($path,'rb')) !== false) {
            try { fseek($file,max(0,fstat($file)['size']-32768)); $text = LogText::decode(fread($file,32768)); } finally { fclose($file); }
            foreach (array_slice(LogText::lines($text),-30) as $line) {
                try { $value = json_decode($line,true,flags:JSON_THROW_ON_ERROR); } catch (\Throwable) { continue; }
                if (is_string($value['time'] ?? null) && is_string($value['message'] ?? null)) { $events[] = ['time'=>$value['time'],'message'=>LogText::redact($value['message'],$values)]; }
            }
        }
        foreach ($this->jobs->recent([$id]) as $job) { $events[] = ['time'=>$job['updated_at'],'message'=>$job['message']]; }
        foreach ($this->runtimeEvents->recent($id) as $event) { $events[] = ['time'=>$event['created_at'],
            'message'=>$event['type'] === 'session.ready' ? 'Spielprotokoll: Sitzung bereit.' : 'Spielprotokoll: Sitzung geschlossen.']; }
        usort($events,fn($a,$b)=>strcmp($b['time'],$a['time'])); return array_slice($events,0,30);
    }
}
