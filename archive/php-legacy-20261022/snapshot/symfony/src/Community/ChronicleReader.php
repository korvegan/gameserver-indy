<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ChronicleReader
{
    public const LATEST = 'NOT EXISTS (SELECT 1 FROM community_events n WHERE n.id=e.id AND n.revision>e.revision)';
    public const ACTIVE = "COALESCE(json_extract(e.payload,'$.removed'),0)=0";
    public const MOMENT = "chronicle_time(json_extract(e.payload,'$.occurred_at'),e.recorded_at)";
    public const SELECT = 'SELECT e.payload,e.recorded_at,s.id AS scene_id,s.position,s.template,
        (SELECT first.game_id FROM community_events first WHERE first.id=e.id ORDER BY first.revision LIMIT 1) AS original_game
        FROM community_events e JOIN scenes s ON s.event_id=e.id';
    public function __construct(private readonly PlatformDatabase $database, private readonly AccessPolicy $policy,
        private readonly GameCatalog $games, private readonly InstanceRegistry $registry) {}
    public function scope(Principal $actor): array
    {
        $this->policy->require($actor, 'community.read');
        if ($actor->serverIds === null) { return ['1', []]; }
        $ids = $actor->serverIds; sort($ids);
        return ['(e.instance_id IS NULL'.($ids ? ' OR e.instance_id IN ('.implode(',', array_fill(0, count($ids), '?')).')' : '').')', $ids];
    }
    public function filters(Principal $actor, array $query, bool $active = true): array
    {
        [$scope, $parameters] = $this->scope($actor); $clauses = [self::LATEST, $scope]; if ($active) { $clauses[] = self::ACTIVE; }
        if (array_diff(array_keys($query), ['game', 'campaign', 'member', 'from', 'to', 'cursor', 'limit']) || array_filter($query, fn ($value) => !is_string($value))) { throw new \InvalidArgumentException('Unbekannter oder ungültiger Chronikfilter.'); }
        foreach (['game' => 'e.game_id', 'campaign' => "json_extract(e.payload,'$.campaign_id')"] as $key => $sql) {
            if (($query[$key] ?? '') !== '') {
                if (!GameCatalog::identifier($query[$key])) { throw new \InvalidArgumentException('Ungültiger Chronikfilter.'); }
                $clauses[] = $sql.'=?'; $parameters[] = $query[$key];
            }
        }
        if (($query['member'] ?? '') !== '') {
            if (!GameCatalog::identifier($query['member'])) { throw new \InvalidArgumentException('Ungültiger Mitgliederfilter.'); }
            $clauses[] = "EXISTS (SELECT 1 FROM json_each(e.payload,'$.member_ids') WHERE value=?)"; $parameters[] = $query['member'];
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (($query[$key] ?? '') !== '') { $clauses[] = self::MOMENT.$operator.'?'; $parameters[] = ChronicleValues::date($query[$key], $key === 'to'); }
        }
        return [implode(' AND ', $clauses), $parameters];
    }
    public static function rows(\PDO $db, string $sql, array $parameters = []): array
    {
        $statement = $db->prepare($sql);
        foreach ($parameters as $index => $value) { $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR)); }
        $statement->execute(); return $statement->fetchAll();
    }
    public function page(Principal $actor, array $query = []): array
    {
        [$where, $parameters] = $this->filters($actor, $query); $limit = $query['limit'] ?? '20'; if ($limit === '') { $limit = '20'; }
        if (!preg_match('/^[0-9]{1,2}$/D', $limit) || (int) $limit < 1 || (int) $limit > 50) { throw new \InvalidArgumentException('Chronikseiten enthalten 1 bis 50 Momente.'); }
        return $this->database->read(function (\PDO $db) use ($where, $parameters, $query, $limit): array {
            $stats = self::rows($db, 'SELECT COUNT(*) AS count,COALESCE(MAX(e.rowid),0) AS maximum,COALESCE(SUM(e.rowid),0) AS sum FROM community_events e WHERE '.$where, $parameters)[0];
            if (($query['cursor'] ?? '') !== '') {
                $cursor = $query['cursor'];
                if (strlen($cursor) > 300 || strlen($cursor) % 4 !== 0 || !preg_match('/^[A-Za-z0-9_\-+\/]*={0,2}$/D', $cursor)) { throw new \InvalidArgumentException('Ungültige Chronikseite.'); }
                $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
                try { $pair = $decoded === false ? null : json_decode($decoded, flags: JSON_THROW_ON_ERROR); } catch (\JsonException) { $pair = null; }
                if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || mb_strlen($pair[0]) > 80 || !is_int($pair[1]) || $pair[1] < 0) { throw new \InvalidArgumentException('Ungültige Chronikseite.'); }
                $where .= ' AND ('.self::MOMENT.',s.position)<(?,?)'; $parameters = [...$parameters, ...$pair];
            }
            $rows = self::rows($db, self::SELECT.' WHERE '.$where.' ORDER BY '.self::MOMENT.' DESC,s.position DESC LIMIT ?', [...$parameters, (int) $limit + 1]);
            $events = array_map(ChronicleValues::event(...), array_slice($rows, 0, (int) $limit)); $next = null;
            if (count($rows) > (int) $limit) { $last = $events[array_key_last($events)]; $next = strtr(base64_encode(ChronicleValues::payload([ChronicleValues::time($last['occurred_at'] ?? null, $last['recorded_at']), $last['scene']['position']])), '+/', '-_'); }
            return ['events' => $events, 'next_cursor' => $next, 'total' => (int) $stats['count'], 'change_token' => implode(':', array_values($stats))];
        });
    }
    public function story(Principal $actor, array $query = []): array
    {
        $chapter = $query['chapter'] ?? null; unset($query['chapter']);
        if (array_intersect(array_keys($query), ['cursor', 'limit']) || ($chapter !== null && (!is_string($chapter) || !preg_match('/^[0-9]{1,9}$/D', $chapter)))) { throw new \InvalidArgumentException('Das Geschichtsbild verwendet gültige Kapitel statt Chronikseiten.'); }
        [$where, $parameters] = $this->filters($actor, $query);
        return $this->database->read(function (\PDO $db) use ($where, $parameters, $chapter): array {
            $chapters = self::rows($db, 'SELECT CAST(s.position/6 AS INTEGER) AS id,COUNT(*) AS count FROM community_events e JOIN scenes s ON s.event_id=e.id WHERE '.$where.' GROUP BY 1 ORDER BY 1', $parameters);
            $selected = $chapter !== null ? (int) $chapter : ($chapters ? (int) $chapters[array_key_last($chapters)]['id'] : 0);
            $rows = self::rows($db, self::SELECT.' WHERE '.$where.' AND s.position>=? AND s.position<? ORDER BY s.position', [...$parameters, $selected * 6, ($selected + 1) * 6]);
            return ['chapter' => $selected, 'chapters' => $chapters, 'events' => array_map(ChronicleValues::event(...), $rows), 'layout' => 'landscape-v1', 'slots' => 6];
        });
    }
    public function detail(Principal $actor, string $id, bool $history = true): array
    {
        [$scope, $parameters] = $this->scope($actor);
        if (!GameCatalog::identifier($id)) { throw new \InvalidArgumentException('Ungültige Moment-ID.'); }
        return $this->database->read(function (\PDO $db) use ($actor, $id, $history, $scope, $parameters): array {
            $rows = self::rows($db, self::SELECT.' WHERE e.id=? AND '.self::LATEST.' AND '.$scope, [$id, ...$parameters]);
            if (!$rows) { throw new AccessDeniedException('Dieser Moment ist nicht vorhanden oder nicht freigegeben.'); }
            $event = ChronicleValues::event($rows[0]);
            if (($event['removed'] ?? false) && $actor->role !== 'owner') { throw new AccessDeniedException('Dieser Moment wurde aus der Chronik entfernt.'); }
            return ['event' => $event, 'history' => $history && $actor->role === 'owner'
                ? array_map(ChronicleValues::event(...), self::rows($db, self::SELECT.' WHERE e.id=? ORDER BY e.revision DESC LIMIT 50', [$id])) : []];
        });
    }
    public function revision(string $id, int $revision): ?array
    {
        return $this->database->read(function (\PDO $db) use ($id, $revision): ?array {
            $rows = self::rows($db, self::SELECT.' WHERE e.id=? AND e.revision=?', [$id, $revision]); return $rows ? ChronicleValues::event($rows[0]) : null;
        });
    }
    public function options(Principal $actor): array
    {
        [$scope, $parameters] = $this->scope($actor); $where = self::LATEST.' AND '.self::ACTIVE.' AND '.$scope;
        return $this->database->read(function (\PDO $db) use ($where, $parameters): array {
            $campaigns = self::rows($db, "SELECT DISTINCT json_extract(e.payload,'$.campaign_id') AS id,json_extract(e.payload,'$.campaign_label') AS label FROM community_events e WHERE ".$where, $parameters);
            $members = self::rows($db, "SELECT DISTINCT m.value AS id,COALESCE(json_extract(e.payload,'$.member_names.' || m.value),m.value) AS name FROM community_events e,json_each(e.payload,'$.member_ids') m WHERE ".$where.' LIMIT 500', $parameters);
            return ['games' => array_map(fn ($game) => ['id' => $game['id'], 'label' => $game['label']], $this->games->entries($this->registry->settings()->catalog_games ?? [])),
                'campaigns' => array_values(array_map(fn ($campaign) => ['id' => $campaign['id'], 'label' => $campaign['label'] ?: $campaign['id']], array_filter($campaigns, fn ($campaign) => (bool) $campaign['id']))), 'members' => $members];
        });
    }
}
