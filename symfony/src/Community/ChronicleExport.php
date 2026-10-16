<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\Principal;

final class ChronicleExport
{
    public function __construct(private readonly PlatformDatabase $database, private readonly ChronicleReader $reader, private readonly AccessPolicy $policy) {}
    public function archive(Principal $actor, array $query = []): string
    {
        $this->policy->require($actor, 'community.export'); unset($query['cursor'], $query['limit']);
        [$where, $parameters] = $this->reader->filters($actor, $query, false);
        return $this->database->read(function (\PDO $db) use ($actor, $where, $parameters): string {
            $ids = ChronicleReader::rows($db, 'SELECT e.id FROM community_events e WHERE '.$where.' LIMIT 1001', $parameters);
            if (count($ids) > 1000) { throw new \DomainException('Bitte den Export auf höchstens 1000 Momente filtern.'); }
            $events = []; $media = []; $size = 0;
            foreach ($ids as $item) {
                $statement = $db->prepare(ChronicleReader::SELECT.' WHERE e.id=? ORDER BY e.revision'); $statement->execute([$item['id']]);
                while ($row = $statement->fetch()) {
                    $event = ChronicleValues::event($row); $size += strlen(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    if ($size > ChronicleValues::EXPORT_LIMIT || count($events) >= 10000) { throw new \DomainException('Export zu groß. Bitte die Auswahl eingrenzen.'); }
                    $events[] = $event;
                    if (isset($event['media_id'])) {
                        if (!preg_match('/^[a-f0-9]{64}$/D', $event['media_id'])) { throw new \DomainException('Ungültige archivierte Medienreferenz.'); }
                        $media[$event['media_id']] = true;
                    }
                }
            }
            $manifest = json_encode(['format' => 'sturmklingen-chronicle', 'version' => 1, 'events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $html = self::html($events); $size = strlen($manifest) + strlen($html);
            if ($size > ChronicleValues::EXPORT_LIMIT) { throw new \DomainException('Export zu groß. Bitte die Auswahl eingrenzen.'); }
            $path = tempnam(sys_get_temp_dir(), 'indy-chronicle-export-');
            if ($path === false) { throw new \RuntimeException('Chronikexport nicht vorbereitbar.'); }
            $zip = new \ZipArchive();
            try {
                chmod($path, 0600);
                if ($zip->open($path, \ZipArchive::OVERWRITE) !== true || !$zip->addFromString('chronicle.json', $manifest) || !$zip->addFromString('index.html', $html)) { throw new \RuntimeException('Chronikexport nicht schreibbar.'); }
                $hashes = array_keys($media); sort($hashes);
                foreach ($hashes as $hash) {
                    $row = CommunityLedger::row($db, 'SELECT content FROM community_media WHERE id=?', [$hash]);
                    if (!$row || !hash_equals($hash, hash('sha256', $row['content']))) { throw new \DomainException('Ein Chronikbild fehlt oder ist beschädigt. Export wurde abgebrochen.'); }
                    $size += strlen($row['content']);
                    if ($size > ChronicleValues::EXPORT_LIMIT) { throw new \DomainException('Export zu groß (maximal 32 MiB). Bitte Kampagne oder Zeitraum eingrenzen.'); }
                    if (!$zip->addFromString('media/'.$hash.'.jpg', $row['content'])) { throw new \RuntimeException('Chronikbild nicht exportierbar.'); }
                }
                if (!$zip->close()) { throw new \RuntimeException('Chronikexport nicht abgeschlossen.'); } $zip = null;
                $this->policy->require($actor, 'community.export'); $bytes = file_get_contents($path);
                return $bytes === false ? throw new \RuntimeException('Chronikexport nicht lesbar.') : $bytes;
            } finally { if ($zip !== null) { $zip->close(); } unlink($path); }
        });
    }
    private static function html(array $events): string
    {
        $latest = []; foreach ($events as $event) { $latest[$event['id']] = $event; }
        uasort($latest, fn ($one, $two) => [ChronicleValues::time($two['occurred_at'] ?? null, $two['recorded_at']), $two['scene']['position']]
            <=> [ChronicleValues::time($one['occurred_at'] ?? null, $one['recorded_at']), $one['scene']['position']]);
        $escape = fn (mixed $value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $cards = '';
        foreach ($latest as $event) {
            $members = implode(', ', array_map(fn ($id) => $event['member_names'][$id] ?? $id, $event['member_ids']));
            $picture = isset($event['media_id']) ? '<img alt="Screenshot" src="media/'.$escape($event['media_id']).'.jpg">' : '';
            $cards .= '<article id="'.$escape($event['id']).'"><h2>'.$escape($event['title']).(($event['removed'] ?? false) ? ' · entfernt' : '').'</h2>'
                .'<p>'.$escape($event['game_id']).' · '.$escape($event['campaign_label'] ?? '').' · '.$escape(($event['occurred_at'] ?? '') ?: $event['recorded_at']).'</p>'
                .'<p>'.$escape($event['description'] ?? '').'</p><p>✦ '.$escape($event['badge_label'] ?? $event['badge_id']).' · '.$escape($members).'</p>'.$picture.'</article>';
        }
        return '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chronik der Sturmklingen</title>'
            .'<style>body{max-width:900px;margin:40px auto;padding:20px;background:#101813;color:#e1e8dc;font:16px/1.6 system-ui}article{padding:24px;margin:20px 0;border:1px solid #49543e;border-radius:8px;overflow-wrap:anywhere}img{max-width:100%;max-height:600px}p{white-space:pre-wrap}</style>'
            .'<h1>Chronik der Sturmklingen</h1><p>Privates Archiv. Sämtliche Versionen und Quellen stehen in chronicle.json. Bilder liegen unter media/.</p>'.$cards.'</html>';
    }
}
