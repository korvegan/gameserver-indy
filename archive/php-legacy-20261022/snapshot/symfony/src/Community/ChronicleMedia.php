<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\Principal;

final class ChronicleMedia
{
    public function __construct(private readonly PlatformDatabase $database, private readonly ChronicleReader $reader, private readonly AccessPolicy $policy) {}
    public function get(Principal $actor, string $eventId, ?string $revision = null): string
    {
        $detail = $this->reader->detail($actor, $eventId); $event = $detail['event'];
        if ($revision !== null && (string) $event['revision'] !== $revision) {
            $this->policy->require($actor, 'community.write'); $event = [];
            foreach ($detail['history'] as $version) { if ((string) $version['revision'] === $revision) { $event = $version; break; } }
        }
        return $this->database->read(function (\PDO $db) use ($event): string {
            $row = CommunityLedger::row($db, 'SELECT content FROM community_media WHERE id=?', [$event['media_id'] ?? '']);
            if (!$row) { throw new \DomainException('Für diesen Moment ist kein Screenshot vorhanden.'); }
            return $row['content'];
        });
    }
}
