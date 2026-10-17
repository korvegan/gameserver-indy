<?php
declare(strict_types=1);
namespace App\Log;

use App\Instance\InstanceRegistry;
use App\Runtime\Readiness;
use App\Runtime\RuntimeEvents;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Incremental, restartable read of complete lines. Cursor contains no raw game text. */
final class GameLogIngestor
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly GameLogSource $source, private readonly EnshroudedLogParser $enshrouded,
        private readonly AskaLogParser $aska, private readonly RuntimeEvents $events) {}
    /** True means another bounded chunk is pending, not a reason for a permanent timer. */
    public function ingest(string $id, array $status): bool
    {
        $identity = Readiness::identity($status);
        $profile = $this->registry->get($id);
        $parser = match ($profile->game) { 'enshrouded'=>$this->enshrouded, 'aska'=>$this->aska, default=>null };
        if (!$parser || !($path = $this->source->path($id)) || !is_file($path)) { return false; }
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('log-cursor:'.$id);
        if (!$lock->acquire()) { return false; }
        $file = null;
        try {
            $folder = $this->runtime->directory.'/data/log-cursors'; PrivateFiles::directory($folder); $cursorPath = $folder.'/'.$id.'.json';
            try { $cursor = json_decode(PrivateFiles::read($cursorPath), true, flags:JSON_THROW_ON_ERROR); } catch (\Throwable) { $cursor = []; }
            $stopped = $identity === null && ($status['known'] ?? false) && ($status['running'] ?? null) === false;
            if ($stopped && isset($cursor['process'])) {
                try { $identity = [$cursor['process'], \App\Community\ChronicleTime::parse(explode(':', $cursor['process'], 2)[1])]; }
                catch (\Throwable) { return false; }
            }
            if (!$identity) { return false; }
            $file = @fopen($path, 'rb'); if (!$file) { return false; }
            $stat = fstat($file); $offset = $cursor['offset'] ?? 0; $fileKey = hash('sha256', $path.':'.$stat['dev'].':'.$stat['ino']);
            fseek($file, max(0, $offset-64)); $anchor = hash('sha256', $offset ? fread($file, min(64, $offset)) : '');
            if (($cursor['process'] ?? null) !== $identity[0] || ($cursor['file'] ?? null) !== $fileKey || $offset>$stat['size']
                || ($cursor['anchor'] ?? null) !== $anchor) {
                $sameProcess = ($cursor['process'] ?? null) === $identity[0];
                $cursor = ['context'=>$sameProcess ? ($cursor['context'] ?? []) : []]; $offset = 0;
            }
            $context = $cursor['context'] ?? []; fseek($file, $offset); $bytes = fread($file, 524288);
            $end = strrpos($bytes, "\n");
            if ($end === false) { return false; } // Keep an incomplete line for its next append.
            foreach (explode("\n", substr($bytes, 0, $end)) as $line) {
                $type = $parser->parse(rtrim($line, "\r"), $identity[1], $context);
                if ($type !== null && ($context['last_type'] ?? null) !== $type) {
                    $context['transition'] = ($context['transition'] ?? 0)+1;
                    $key = $identity[0].':'.$context['transition'].':'.$type;
                    if (!$stopped || $type !== 'session.ready') { $this->events->append($id, $key, $type); }
                    $context['last_type'] = $type;
                }
                $offset += strlen($line)+1;
            }
            fseek($file, max(0, $offset-64)); $anchor = hash('sha256', fread($file, min(64, $offset)));
            PrivateFiles::write($cursorPath, json_encode(['process'=>$identity[0], 'file'=>$fileKey, 'offset'=>$offset,
                'anchor'=>$anchor, 'context'=>$context], JSON_THROW_ON_ERROR));
            return $offset < $stat['size'] && strlen($bytes) === 524288;
        } finally { if (is_resource($file)) { fclose($file); } $lock->release(); }
    }
}
