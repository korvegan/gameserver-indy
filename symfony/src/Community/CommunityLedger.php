<?php
declare(strict_types=1);
namespace App\Community;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;

/** Durable event, scene and outbox contracts. No implicit awards or live sender. */
final class CommunityLedger
{
    public function __construct(private readonly PlatformDatabase $database, private readonly GameCatalog $games, private readonly InstanceRegistry $registry) {}
    public function record(\stdClass $event, bool $publish = false, string $target = 'discord-stub', ?array $media = null): array
    {
        $event = clone $event;
        $allowed = ['id', 'revision', 'title', 'description', 'member_ids', 'badge_id', 'source', 'occurred_at', 'game_id', 'instance_id',
            'campaign_id', 'mod_profile_id', 'member_names', 'campaign_label', 'badge_label', 'actor_id', 'correction_reason', 'removed', 'media_id'];
        if (array_diff(array_keys(get_object_vars($event)), $allowed) || !GameCatalog::identifier($event->game_id ?? null)) { throw new \InvalidArgumentException('Unbekannte Felder oder fehlendes Spiel im Gemeinschaftsereignis.'); }
        foreach (['instance_id', 'campaign_id', 'mod_profile_id'] as $key) {
            if (($event->{$key} ?? null) !== null && !GameCatalog::identifier($event->{$key})) { throw new \InvalidArgumentException('Ungültiger Ereigniskontext.'); }
            $event->{$key} ??= null;
        }
        $known = array_column($this->games->entries($this->registry->settings()->catalog_games ?? []), 'id');
        if (!in_array($event->game_id, $known, true) || ($event->instance_id !== null && $this->registry->get($event->instance_id)->game !== $event->game_id)) { throw new \InvalidArgumentException('Spiel und Serverinstanz des Ereignisses stimmen nicht überein.'); }
        $members = $event->member_ids ?? null;
        if (!GameCatalog::identifier($event->id ?? null) || !is_int($event->revision ?? null) || $event->revision < 1
            || !GameCatalog::identifier($event->badge_id ?? null) || !is_array($members) || !array_is_list($members) || count($members) < 1 || count($members) > 16
            || array_filter($members, fn ($member) => !GameCatalog::identifier($member)) || count(array_unique($members)) !== count($members)
            || !in_array($event->source ?? null, ['test', 'manual', 'verified-game', 'steam-import'], true) || !GameCatalog::identifier($target)) {
            throw new \InvalidArgumentException('Ungültige Ereignisrevision, Quelle oder Mitgliederzuordnung.');
        }
        foreach (['title' => 160, 'description' => 1000, 'occurred_at' => 80, 'campaign_label' => 100, 'badge_label' => 80, 'actor_id' => 160, 'correction_reason' => 300] as $key => $limit) {
            if (property_exists($event, $key) && (!is_string($event->{$key}) || mb_strlen($event->{$key}) > $limit || !mb_check_encoding($event->{$key}, 'UTF-8'))) { throw new \InvalidArgumentException('Ungültige Chronikmetadaten.'); }
        }
        ChronicleValues::text($event->title ?? '', 160, true);
        $names = $event->member_names ?? new \stdClass();
        if (!$names instanceof \stdClass || array_diff(array_keys(get_object_vars($names)), $members)
            || array_filter(get_object_vars($names), fn ($name) => !is_string($name) || mb_strlen($name) < 1 || mb_strlen($name) > 80)
            || (property_exists($event, 'removed') && !is_bool($event->removed))
            || (property_exists($event, 'media_id') && (!is_string($event->media_id) || !preg_match('/^[a-f0-9]{64}$/D', $event->media_id)))) { throw new \InvalidArgumentException('Ungültige Mitgliedernamen oder Medienreferenz.'); }
        $payload = ChronicleValues::payload($event);
        return $this->database->write(function (\PDO $db) use ($event, $payload, $publish, $target, $media): array {
            if ($media !== null) {
                if (hash('sha256', $media[0]) !== ($event->media_id ?? null)) { throw new \InvalidArgumentException('Screenshot und Referenz stimmen nicht überein.'); }
                $statement = $db->prepare('INSERT OR IGNORE INTO community_media VALUES (?,?,?,?)');
                $statement->bindValue(1, $event->media_id); $statement->bindValue(2, $media[0], \PDO::PARAM_LOB);
                $statement->bindValue(3, $media[1], \PDO::PARAM_INT); $statement->bindValue(4, $media[2], \PDO::PARAM_INT); $statement->execute();
            }
            if (isset($event->media_id) && !self::row($db, 'SELECT 1 FROM community_media WHERE id=?', [$event->media_id])) { throw new \DomainException('Der referenzierte Screenshot fehlt.'); }
            $existing = self::row($db, 'SELECT revision,payload FROM community_events WHERE id=? ORDER BY revision DESC LIMIT 1', [$event->id]);
            if ($existing && $event->revision <= (int) $existing['revision']) {
                $previous = self::row($db, 'SELECT payload FROM community_events WHERE id=? AND revision=?', [$event->id, $event->revision]);
                if (!$previous || ChronicleValues::canonical(json_decode($previous['payload'], flags: JSON_THROW_ON_ERROR)) !== ChronicleValues::canonical($event)) { throw new \DomainException('Diese Ereignisrevision existiert bereits mit anderem Inhalt.'); }
            } else {
                if ($event->revision !== ($existing ? (int) $existing['revision'] + 1 : 1)) { throw new \DomainException('Ereignisrevisionen müssen lückenlos aufeinander folgen.'); }
                $db->prepare('INSERT INTO community_events VALUES (?,?,?,?,?,?)')->execute([$event->id, $event->revision, $event->game_id, $event->instance_id, $payload,
                    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);
                $position = (int) $db->query('SELECT COALESCE(MAX(position),-1)+1 FROM scenes')->fetchColumn();
                $db->prepare('INSERT OR IGNORE INTO scenes VALUES (?,?,?,?)')->execute(['scene-'.$event->id, $event->id, $position, ChronicleValues::blueprint($event->game_id)]);
                $db->prepare("UPDATE deliveries SET state='superseded' WHERE event_id=? AND revision<? AND state IN ('pending','failed')")->execute([$event->id, $event->revision]);
            }
            if ($publish && (!$existing || $event->revision >= (int) $existing['revision'])) { $db->prepare("INSERT OR IGNORE INTO deliveries VALUES (?,?,?,'pending',NULL)")->execute([$event->id, $event->revision, $target]); }
            return ['event_id' => $event->id, 'revision' => $event->revision, 'scene_id' => 'scene-'.$event->id];
        });
    }
    public static function row(\PDO $db, string $sql, array $parameters = []): array|false
    {
        $statement = $db->prepare($sql); $statement->execute($parameters); return $statement->fetch();
    }
    /** Explicit injected transport. Ambiguous sends and older revisions are never replayed. */
    public function deliverPending(callable $sender): void
    {
        $rows = $this->database->read(fn (\PDO $db) => $db->query("SELECT * FROM deliveries WHERE state='pending' LIMIT 8")->fetchAll());
        foreach ($rows as $row) {
            $key = [$row['event_id'], (int) $row['revision'], $row['target']];
            $delivery = $this->database->write(function (\PDO $db) use ($key): ?array {
                $latest = self::row($db, 'SELECT MAX(revision) AS revision FROM community_events WHERE id=?', [$key[0]]);
                if ($key[1] < (int) $latest['revision']) { $db->prepare("UPDATE deliveries SET state='superseded' WHERE event_id=? AND revision=? AND target=? AND state='pending'")->execute($key); return null; }
                if (self::row($db, "SELECT 1 FROM deliveries WHERE event_id=? AND target=? AND state='uncertain' LIMIT 1", [$key[0], $key[2]])) {
                    $db->prepare("UPDATE deliveries SET state='uncertain' WHERE event_id=? AND revision=? AND target=? AND state='pending'")->execute($key); return null;
                }
                if (self::row($db, "SELECT 1 FROM deliveries WHERE event_id=? AND target=? AND state='sending' LIMIT 1", [$key[0], $key[2]])) { return null; }
                $statement = $db->prepare("UPDATE deliveries SET state='sending' WHERE event_id=? AND revision=? AND target=? AND state='pending'"); $statement->execute($key);
                if ($statement->rowCount() !== 1) { return null; }
                $event = self::row($db, 'SELECT payload FROM community_events WHERE id=? AND revision=?', array_slice($key, 0, 2));
                $previous = self::row($db, "SELECT remote_id FROM deliveries WHERE event_id=? AND target=? AND revision<? AND state='sent' ORDER BY revision DESC LIMIT 1", [$key[0], $key[2], $key[1]]);
                return [json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR), $previous ? $previous['remote_id'] : null];
            });
            if ($delivery === null) { continue; }
            [$event, $previous] = $delivery; $state = 'uncertain'; $remote = null;
            try {
                $remote = $sender($key[2], ['event_id' => $event['id'], 'revision' => $event['revision'], 'scene_id' => 'scene-'.$event['id'], 'content' => $event['title'],
                    'allowed_mentions' => ['parse' => []], 'template_version' => 1, 'operation' => $previous === null ? 'create' : 'update', 'previous_remote_id' => $previous]);
                if (!is_string($remote) || $remote === '') { $remote = null; } else { $state = 'sent'; }
            } catch (\Throwable) { $remote = null; }
            $this->database->write(fn (\PDO $db) => $db->prepare('UPDATE deliveries SET state=?,remote_id=? WHERE event_id=? AND revision=? AND target=?')->execute([$state, $remote, ...$key]));
        }
    }
}
