<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;

/** Manual evidence only. Names never become verified Steam/Discord identities. */
final class ChronicleWriter
{
    public function __construct(private readonly AccessPolicy $policy, private readonly ChronicleReader $reader, private readonly CommunityLedger $ledger) {}
    public function save(Principal $actor, \stdClass $body): array
    {
        $this->policy->require($actor, 'community.write');
        if (array_diff(array_keys(get_object_vars($body)), ['id', 'revision', 'title', 'description', 'game_id', 'instance_id', 'campaign', 'members', 'badge', 'occurred_at', 'reason', 'remove', 'image', 'clear_image'])
            || !GameCatalog::identifier($body->id ?? null) || !is_int($body->revision ?? null)) { throw new \InvalidArgumentException('Ungültiger Chronikbeitrag.'); }
        foreach (['remove', 'clear_image'] as $key) { if (property_exists($body, $key) && !is_bool($body->{$key})) { throw new \InvalidArgumentException('Ungültige Auswahl zum Entfernen.'); } }
        $previous = null;
        if ($body->revision > 1) {
            $this->reader->detail($actor, $body->id, false);
            $previous = $this->reader->revision($body->id, $body->revision - 1);
            if ($previous === null) { throw new \DomainException('Ereignisrevisionen müssen lückenlos aufeinander folgen.'); }
        }
        if ($previous !== null && $previous['source'] !== 'manual') { throw new \DomainException('Importierte Nachweise können hier nicht manuell überschrieben werden.'); }
        $reason = ChronicleValues::text($body->reason ?? '', 300, $previous !== null);
        if ($body->remove ?? false) {
            if ($previous === null) { throw new \InvalidArgumentException('Nur vorhandene Momente können entfernt werden.'); }
            $event = array_diff_key($previous, array_flip(['scene', 'awards', 'recorded_at']));
            $event = [...$event, 'revision' => $body->revision, 'removed' => true, 'actor_id' => $actor->id, 'correction_reason' => $reason];
            $this->policy->require($actor, 'community.write', $event['instance_id'] ?? null);
            return $this->ledger->record(json_decode(json_encode($event, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
        }
        if ($previous['removed'] ?? false) { throw new \DomainException('Ein entfernter Moment wird nicht stillschweigend wiederhergestellt.'); }
        $game = $body->game_id ?? null; $instance = $body->instance_id ?? null; if ($instance === '') { $instance = null; }
        if ($instance !== null && !is_string($instance)) { throw new \InvalidArgumentException('Ungültige Serverzuordnung.'); }
        $this->policy->require($actor, 'community.write', $instance);
        if ($previous !== null && ($game !== $previous['game_id'] || $instance !== ($previous['instance_id'] ?? null))) { throw new \DomainException('Spiel und Serverzuordnung bleiben bei Korrekturen erhalten.'); }
        if (!is_array($body->members ?? null) || !array_is_list($body->members) || count($body->members) < 1 || count($body->members) > 16) { throw new \InvalidArgumentException('Bitte 1 bis 16 Beteiligte angeben.'); }
        $previousNames = []; $names = [];
        foreach ($previous['member_ids'] ?? [] as $member) { $previousNames[ChronicleValues::normalized($previous['member_names'][$member] ?? $member)] = $member; }
        foreach ($body->members as $value) {
            $name = ChronicleValues::text($value, 80, true); $member = $previousNames[ChronicleValues::normalized($name)] ?? ChronicleValues::nameId('member-', $name);
            if (isset($names[$member])) { throw new \InvalidArgumentException('Beteiligte bitte jeweils nur einmal eintragen.'); } $names[$member] = $name;
        }
        $occurred = ChronicleValues::occurred(ChronicleValues::text($body->occurred_at ?? '', 80));
        $campaign = ChronicleValues::text($body->campaign ?? '', 100); $badge = ChronicleValues::text($body->badge ?? '', 80, true);
        $campaignId = $campaign !== '' ? ChronicleValues::nameId('campaign-', $campaign) : null; $badgeId = ChronicleValues::nameId('badge-', $badge);
        if ($previous !== null) {
            if ($campaign === (($previous['campaign_label'] ?? '') ?: ($previous['campaign_id'] ?? ''))) { $campaignId = $previous['campaign_id'] ?? null; }
            if ($badge === (($previous['badge_label'] ?? '') ?: $previous['badge_id'])) { $badgeId = $previous['badge_id']; }
        }
        $event = (object) ['id' => $body->id, 'revision' => $body->revision, 'game_id' => $game, 'instance_id' => $instance,
            'campaign_id' => $campaignId, 'campaign_label' => $campaign, 'mod_profile_id' => $previous['mod_profile_id'] ?? null,
            'title' => ChronicleValues::text($body->title ?? '', 160, true), 'description' => ChronicleValues::text($body->description ?? '', 1000),
            'source' => 'manual', 'member_ids' => array_keys($names), 'member_names' => (object) $names, 'badge_id' => $badgeId, 'badge_label' => $badge,
            'occurred_at' => $occurred, 'actor_id' => $actor->id, 'correction_reason' => $reason];
        $media = null;
        if (isset($body->image) && $body->image !== '') {
            if (!is_string($body->image) || strlen($body->image) % 4 !== 0 || strlen($body->image) > (int) ceil(ChronicleValues::IMAGE_LIMIT / 3) * 4
                || !preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $body->image) || ($bytes = base64_decode($body->image, true)) === false) { throw new \InvalidArgumentException('Das Vorschaubild ist ungültig kodiert.'); }
            $media = ChronicleValues::jpeg($bytes); $event->media_id = hash('sha256', $bytes);
        } elseif ($previous !== null && !($body->clear_image ?? false) && isset($previous['media_id'])) { $event->media_id = $previous['media_id']; }
        $this->policy->require($actor, 'community.write', $instance);
        return [...$this->ledger->record($event, media: $media), 'message' => 'Moment gespeichert. Alle Beteiligten erhalten dieselbe Sturmklingen-Auszeichnung.'];
    }
}
