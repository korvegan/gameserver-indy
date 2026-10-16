<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Community\ChronicleExport;
use App\Community\ChronicleMedia;
use App\Community\ChronicleReader;
use App\Community\ChronicleSnapshot;
use App\Community\ChronicleValues;
use App\Community\ChronicleWriter;
use App\Community\CommunityLedger;
use App\Community\PlatformDatabase;
use App\Storage\PrivateFiles;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ChronicleTest extends SliceTestCase
{
    private const JPEG = 'ffd8ffc00011080001000103011100021100031100ffda0008010100003f0000ffd9';
    private Principal $owner;
    private PlatformDatabase $database;
    private ChronicleWriter $writer;
    private ChronicleReader $reader;
    protected function setUp(): void
    {
        parent::setUp(); static::bootKernel(); $container = static::getContainer();
        $this->owner = new Principal('local-owner', 'owner'); $container->get(AccessStore::class)->initialize();
        PrivateFiles::write($this->directory.'/manager.local.json', PrivateFiles::json((object) ['schema_version' => 2, 'servers' => [
            (object) ['id' => 'enshrouded', 'game' => 'enshrouded', 'label' => 'Own mountains', 'server_directory' => $this->directory.'/own mountains'],
            (object) ['id' => 'aska', 'game' => 'aska', 'label' => 'Own woodland', 'server_directory' => $this->directory.'/own woodland']]]));
        $this->database = $container->get(PlatformDatabase::class); $this->database->initialize();
        $this->writer = $container->get(ChronicleWriter::class); $this->reader = $container->get(ChronicleReader::class);
    }
    private function body(string $id = 'first-moment', array $changes = []): \stdClass
    {
        return (object) [...['id' => $id, 'revision' => 1, 'game_id' => 'enshrouded', 'instance_id' => 'enshrouded', 'title' => 'Unser erstes Lager',
            'description' => 'Gemeinsam begonnen.', 'campaign' => 'Neue Welt', 'members' => ['Eins', 'Zwei', 'Drei', 'Vier'], 'badge' => 'Lagergründer', 'occurred_at' => '2026-10-15T20:15:00+02:00'], ...$changes];
    }
    private function rejected(callable $action): void
    {
        try { $action(); self::fail('Invalid or conflicting contribution was accepted'); }
        catch (\InvalidArgumentException|\DomainException|AccessDeniedException) { self::assertTrue(true); }
    }
    public function testManualMomentsShareAwardsAreIdempotentAndNeverQueueDeliveries(): void
    {
        $body = $this->body(); $first = $this->writer->save($this->owner, $body);
        self::assertSame($first, $this->writer->save($this->owner, $body));
        $page = $this->reader->page($this->owner); self::assertSame(1, $page['total']); $event = $page['events'][0];
        self::assertCount(4, $event['awards']); self::assertCount(1, array_unique(array_column($event['awards'], 'badge_id')));
        self::assertSame('2026-10-15T18:15:00+00:00', $event['occurred_at']); self::assertSame('manual', $event['source']);
        self::assertSame('local-owner', $event['actor_id']); self::assertSame('scene-first-moment', $event['scene']['id']);
        self::assertSame(0, (int) $this->database->connect()->query('SELECT COUNT(*) FROM deliveries')->fetchColumn());
        self::assertSame(['Eins', 'Zwei', 'Drei', 'Vier'], array_column($this->reader->options($this->owner)['members'], 'name'));
    }
    public function testHistoricalRetriesPreserveImagesAfterCorrectionAndRemoval(): void
    {
        $body = $this->body(changes: ['image' => base64_encode(hex2bin(self::JPEG))]); $this->writer->save($this->owner, $body);
        $before = $this->reader->detail($this->owner, $body->id)['event'];
        $second = $this->body(changes: ['revision' => 2, 'reason' => 'Titel präzisiert', 'title' => 'Am Fluss']); $this->writer->save($this->owner, $second);
        $third = $this->body(changes: ['revision' => 3, 'reason' => 'Bild entfernt', 'title' => 'Am Fluss', 'clear_image' => true]); $this->writer->save($this->owner, $third);
        $remove = (object) ['id' => $body->id, 'revision' => 4, 'remove' => true, 'reason' => 'Archiviert']; $this->writer->save($this->owner, $remove);
        foreach ([$body, $second, $third, $remove] as $retry) { $this->writer->save($this->owner, $retry); }
        $detail = $this->reader->detail($this->owner, $body->id); self::assertCount(4, $detail['history']); self::assertTrue($detail['event']['removed']);
        self::assertArrayNotHasKey('media_id', $detail['event']); self::assertSame($before['scene'], $detail['event']['scene']);
        self::assertSame(hex2bin(self::JPEG), static::getContainer()->get(ChronicleMedia::class)->get($this->owner, $body->id, '2'));
        self::assertSame([], $this->reader->page($this->owner)['events']);
        $this->rejected(fn () => $this->writer->save($this->owner, $this->body(changes: ['revision' => 2, 'reason' => 'Konflikt'])));
    }
    public function testPagesFiltersChangeTokensAndFixedChapterSlotsRemainStable(): void
    {
        for ($index = 0; $index < 25; ++$index) { $this->writer->save($this->owner, $this->body('event-'.$index, ['occurred_at' => sprintf('2026-10-%02dT12:00:00Z', $index + 1)])); }
        $first = $this->reader->page($this->owner, ['limit' => '10']); $second = $this->reader->page($this->owner, ['limit' => '10', 'cursor' => $first['next_cursor']]);
        $third = $this->reader->page($this->owner, ['limit' => '10', 'cursor' => $second['next_cursor']]);
        self::assertCount(25, array_unique(array_column([...$first['events'], ...$second['events'], ...$third['events']], 'id'))); self::assertNull($third['next_cursor']);
        $event = $first['events'][0]; self::assertSame('event-24', $event['id']);
        self::assertSame(1, $this->reader->page($this->owner, ['game' => 'enshrouded', 'campaign' => $event['campaign_id'], 'member' => $event['member_ids'][0], 'from' => '2026-10-25', 'to' => '2026-10-25'])['total']);
        $this->writer->save($this->owner, (object) ['id' => 'event-2', 'revision' => 2, 'remove' => true, 'reason' => 'Doppelt']);
        self::assertNotSame($first['change_token'], $this->reader->page($this->owner)['change_token']);
        self::assertSame([0, 1, 3, 4, 5], array_column(array_column($this->reader->story($this->owner, ['chapter' => '0'])['events'], 'scene'), 'slot'));
        self::assertSame(6, $this->reader->story($this->owner, ['chapter' => '1'])['events'][0]['scene']['position']);
    }
    public function testNamesUseUnicodeNormalizationAndLegacyIdentifiersStayUnchanged(): void
    {
        $this->writer->save($this->owner, $this->body('one', ['members' => ['Änne', 'Ｓtraße']]));
        $this->writer->save($this->owner, $this->body('two', ['members' => ['ÄNNE', 'strasse']]));
        $events = $this->reader->page($this->owner)['events']; self::assertSame($events[0]['member_ids'], $events[1]['member_ids']);
        $this->rejected(fn () => $this->writer->save($this->owner, $this->body('bad', ['members' => ['Änne', 'ÄNNE']])));
        $ledger = static::getContainer()->get(CommunityLedger::class);
        $ledger->record((object) ['id' => 'legacy', 'revision' => 1, 'game_id' => 'aska', 'instance_id' => 'aska', 'title' => 'Alt', 'source' => 'manual',
            'member_ids' => ['old-one', 'old-two'], 'badge_id' => 'old-badge', 'campaign_id' => 'old-campaign', 'mod_profile_id' => 'old-mods']);
        $db = $this->database->connect(); $db->exec("UPDATE scenes SET template='milestone' WHERE event_id='legacy'"); unset($db);
        $this->writer->save($this->owner, $this->body('legacy', ['revision' => 2, 'game_id' => 'aska', 'instance_id' => 'aska', 'members' => ['old-one', 'old-two'], 'badge' => 'old-badge', 'campaign' => 'old-campaign', 'reason' => 'Tippfehler']));
        $event = $this->reader->detail($this->owner, 'legacy')['event']; self::assertSame(['old-one', 'old-two'], $event['member_ids']);
        self::assertSame('old-badge', $event['badge_id']); self::assertSame('old-campaign', $event['campaign_id']); self::assertSame('old-mods', $event['mod_profile_id']);
        self::assertSame('milestone', $event['scene']['template']); self::assertSame('landscape-v1-woodland', $event['scene']['blueprint']);
    }
    public function testMalformedInputAndImagesLeaveNoOrphans(): void
    {
        foreach ([['title' => ''], ['members' => []], ['members' => array_fill(0, 17, 'x')], ['source' => 'verified-game'], ['revision' => true],
            ['occurred_at' => '2026-10-15T20:15'], ['occurred_at' => '2026-02-30T12:00:00Z'], ['occurred_at' => '2026-10-15T12:00:00+25:00'],
            ['image' => 'wrong'], ['image' => 'YQ'], ['image' => base64_encode('<svg/>')], ['instance_id' => 'aska']] as $changes) {
            $this->rejected(fn () => $this->writer->save($this->owner, $this->body(changes: $changes)));
        }
        $db = $this->database->connect(); self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM community_events')->fetchColumn()); self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM community_media')->fetchColumn());
        foreach ([['cursor' => '%'], ['game' => "' OR 1=1"], ['from' => 'bad'], ['to' => '9999-12-31'], ['limit' => '0'], ['limit' => '51'], ['unknown' => 'x']] as $query) { $this->rejected(fn () => $this->reader->page($this->owner, $query)); }
        foreach ([['chapter' => '-1'], ['chapter' => '١'], ['chapter' => 1], ['limit' => '1'], ['cursor' => 'x']] as $query) { $this->rejected(fn () => $this->reader->story($this->owner, $query)); }
    }
    public function testEmptyNameObjectsAndNumericLegacyMemberIdsSurviveCorrectionsAndRemoval(): void
    {
        $ledger = static::getContainer()->get(CommunityLedger::class);
        $event = (object) ['id' => 'empty-names', 'revision' => 1, 'game_id' => 'enshrouded', 'title' => 'Old names', 'source' => 'manual', 'member_ids' => ['one'], 'member_names' => new \stdClass(), 'badge_id' => 'first'];
        $ledger->record($event); self::assertInstanceOf(\stdClass::class, $this->reader->detail($this->owner, 'empty-names')['event']['member_names']);
        $this->writer->save($this->owner, (object) ['id' => 'empty-names', 'revision' => 2, 'remove' => true, 'reason' => 'Archived']);
        self::assertInstanceOf(\stdClass::class, $this->reader->detail($this->owner, 'empty-names')['event']['member_names']);
        $event->id = 'numeric-names'; $event->member_ids = ['0', '76561198000000001', '10', '2']; $event->member_names = (object) ['0' => 'Zero', '76561198000000001' => 'One', '10' => 'Ten', '2' => 'Two']; $ledger->record($event);
        $body = $this->body('numeric-names', ['revision' => 2, 'instance_id' => null, 'members' => ['Zero', 'One', 'Ten', 'Two'], 'badge' => 'first', 'campaign' => '', 'reason' => 'Correction']);
        $this->writer->save($this->owner, $body); $detail = $this->reader->detail($this->owner, 'numeric-names')['event'];
        self::assertSame($event->member_ids, $detail['member_ids']); self::assertSame('Zero', $detail['member_names']->{'0'});
        self::assertSame('{"10": "Ten", "2": "Two"}', ChronicleValues::payload((object) ['2' => 'Two', '10' => 'Ten']));
    }
    public function testScopedFamilyReadAndActualRevocationProtectDetailsMediaAndExports(): void
    {
        $store = static::getContainer()->get(AccessStore::class); $store->configure($this->owner, ['enabled' => true, 'hostname' => 'indy.private.ts.net']);
        $grant = ['login' => 'relative@example.invalid', 'name' => 'Relative', 'role' => 'family', 'server_ids' => ['aska']];
        $created = $store->saveUser($this->owner, $grant, ['aska', 'enshrouded']); $actor = $store->principal($grant['login']);
        $this->writer->save($this->owner, $this->body(changes: ['image' => base64_encode(hex2bin(self::JPEG))]));
        $this->rejected(fn () => $this->reader->detail($actor, 'first-moment')); $this->rejected(fn () => $this->writer->save($actor, $this->body()));
        $this->rejected(fn () => static::getContainer()->get(ChronicleExport::class)->archive($actor));
        $this->writer->save($this->owner, $this->body('allowed', ['game_id' => 'aska', 'instance_id' => 'aska', 'image' => base64_encode(hex2bin(self::JPEG))]));
        self::assertSame(['allowed'], array_column($this->reader->page($actor)['events'], 'id')); self::assertSame([], $this->reader->detail($actor, 'allowed')['history']);
        self::assertSame(hex2bin(self::JPEG), static::getContainer()->get(ChronicleMedia::class)->get($actor, 'allowed'));
        $store->saveUser($this->owner, [...$grant, 'id' => $created['id'], 'revision' => 1, 'enabled' => false], ['aska', 'enshrouded']);
        $this->rejected(fn () => $this->reader->page($actor));
    }
    public function testUtcSortingAndEscapedExportIncludeEveryRevisionAndExactMedia(): void
    {
        $ledger = static::getContainer()->get(CommunityLedger::class);
        foreach (['early-offset' => '2026-10-15T00:30:00+02:00', 'later-z' => '2026-10-14T23:30:00Z', 'same-instant' => '2026-10-15T01:30:00+02:00'] as $id => $time) {
            $ledger->record((object) ['id' => $id, 'revision' => 1, 'game_id' => 'enshrouded', 'title' => $id, 'source' => 'verified-game', 'member_ids' => ['legacy-one'], 'badge_id' => 'badge', 'occurred_at' => $time]);
        }
        self::assertSame(['same-instant', 'later-z', 'early-offset'], array_column($this->reader->page($this->owner)['events'], 'id'));
        self::assertSame(0, $this->reader->page($this->owner, ['from' => '2026-10-15', 'to' => '2026-10-15'])['total']);
        self::assertSame(3, $this->reader->page($this->owner, ['from' => '2026-10-14', 'to' => '2026-10-14'])['total']);
        $this->writer->save($this->owner, $this->body(changes: ['title' => '<script>alert(1)</script>', 'description' => '<img onerror=bad()>', 'image' => base64_encode(hex2bin(self::JPEG))]));
        $this->writer->save($this->owner, (object) ['id' => 'first-moment', 'revision' => 2, 'remove' => true, 'reason' => 'Archiviert']);
        $bytes = static::getContainer()->get(ChronicleExport::class)->archive($this->owner); $path = $this->directory.'/own-export.zip'; file_put_contents($path, $bytes);
        $zip = new \ZipArchive(); self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        try {
            $manifest = json_decode($zip->getFromName('chronicle.json'), true, flags: JSON_THROW_ON_ERROR); self::assertCount(5, $manifest['events']);
            self::assertSame(hex2bin(self::JPEG), $zip->getFromName('media/'.hash('sha256', hex2bin(self::JPEG)).'.jpg'));
            $html = $zip->getFromName('index.html'); self::assertStringContainsString('&lt;script&gt;', $html); self::assertStringNotContainsString('<script>', $html); self::assertStringNotContainsString('<img onerror', $html);
            self::assertStringContainsString('entfernt', $html);
        } finally { $zip->close(); }
    }
    public function testExplicitSnapshotPreservesV2BytesReferencesAndV1RollbackWithoutAccessData(): void
    {
        $this->writer->save($this->owner, $this->body(changes: ['image' => base64_encode(hex2bin(self::JPEG))]));
        $access = hash_file('sha256', $this->directory.'/access.sqlite3'); $original = hash_file('sha256', $this->database->path());
        $snapshot = static::getContainer()->get(ChronicleSnapshot::class); $manifest = $snapshot->copy($this->database->path(), $this->directory.'/v2-package');
        self::assertSame(2, $manifest['source_schema']); self::assertSame(1, $manifest['tables']['community_events']['rows']); self::assertSame(1, $manifest['tables']['community_media']['rows']);
        self::assertSame($access, hash_file('sha256', $this->directory.'/access.sqlite3')); self::assertSame($original, hash_file('sha256', $this->database->path()));
        $this->rejected(fn () => $snapshot->copy($this->database->path(), $this->directory.'/v2-package'));
        $v1 = $this->directory.'/v1.sqlite3'; copy($this->database->path(), $v1); $db = PlatformDatabase::open($v1);
        $db->exec("UPDATE community_events SET payload=json_remove(payload,'$.media_id'); DROP TABLE community_media; PRAGMA user_version=1"); unset($db); $before = hash_file('sha256', $v1);
        $manifest = $snapshot->copy($v1, $this->directory.'/v1-package'); self::assertSame(1, $manifest['source_schema']); self::assertSame($before, hash_file('sha256', $v1));
        $saved = PlatformDatabase::open($this->directory.'/v1-package/source.sqlite3'); self::assertSame(1, (int) $saved->query('PRAGMA user_version')->fetchColumn()); unset($saved);
        $working = PlatformDatabase::open($this->directory.'/v1-package/platform.sqlite3'); self::assertSame(2, (int) $working->query('PRAGMA user_version')->fetchColumn()); unset($working);
        self::assertSame(1, $manifest['tables']['community_events']['rows']); self::assertSame(1, $manifest['tables']['scenes']['rows']);
        self::assertFileDoesNotExist($this->directory.'/v1-package/access.sqlite3');
    }
    public function testOutboxKeepsRemoteMappingAndNeverReplaysUncertainOrOldRevisions(): void
    {
        $ledger = static::getContainer()->get(CommunityLedger::class); $event = (object) ['id' => 'published', 'revision' => 1, 'game_id' => 'aska', 'title' => 'Erster Moment', 'source' => 'test', 'member_ids' => ['one'], 'badge_id' => 'first'];
        $ledger->record($event, true); $sent = []; $sender = function ($target, $card) use (&$sent) { $sent[] = $card; return 'own-remote'; };
        $ledger->deliverPending($sender); $ledger->record($event, true); $ledger->deliverPending($sender); self::assertCount(1, $sent);
        $next = clone $event; $next->revision = 2; $next->title = 'Korrektur'; $ledger->record($next, true); $ledger->record($event, true); $ledger->deliverPending($sender);
        self::assertCount(2, $sent); self::assertSame('update', $sent[1]['operation']); self::assertSame('own-remote', $sent[1]['previous_remote_id']);
        $next->revision = 3; $ledger->record($next, true); $ledger->deliverPending(fn () => throw new \RuntimeException('Uncertain transport'));
        $next->revision = 4; $ledger->record($next, true); $ledger->deliverPending($sender); self::assertCount(2, $sent);
        self::assertSame('uncertain', $this->database->connect()->query("SELECT state FROM deliveries WHERE revision=4")->fetchColumn());
    }
    public function testInterruptedDeliveriesRequireExplicitReconciliationAndNeverImplicitReplay(): void
    {
        $ledger = static::getContainer()->get(CommunityLedger::class);
        $ledger->record((object) ['id' => 'interrupted', 'revision' => 1, 'game_id' => 'aska', 'title' => 'Own moment', 'source' => 'test', 'member_ids' => ['one'], 'badge_id' => 'first'], true);
        $db = $this->database->connect(); $db->exec("UPDATE deliveries SET state='sending'"); unset($db);
        $this->reader->page($this->owner); self::assertSame('sending', $this->database->connect()->query('SELECT state FROM deliveries')->fetchColumn());
        $this->database->reconcileDeliveries(); $sent = []; $ledger->deliverPending(function ($target, $card) use (&$sent) { $sent[] = $card; return 'own'; });
        self::assertSame([], $sent); self::assertSame('uncertain', $this->database->connect()->query('SELECT state FROM deliveries')->fetchColumn());
    }
    public function testSnapshotRejectsDamagedImageWithoutTouchingSourceOrLeavingPackage(): void
    {
        $this->writer->save($this->owner, $this->body(changes: ['image' => base64_encode(hex2bin(self::JPEG))]));
        $db = $this->database->connect(); $db->exec("UPDATE community_media SET content=X'0001'"); unset($db); $before = hash_file('sha256', $this->database->path());
        $this->rejected(fn () => static::getContainer()->get(ChronicleSnapshot::class)->copy($this->database->path(), $this->directory.'/rejected-package'));
        self::assertDirectoryDoesNotExist($this->directory.'/rejected-package'); self::assertSame($before, hash_file('sha256', $this->database->path()));
    }
    public function testHttpContributionCsrfImageAndPrivateExportContract(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient([], ['HTTP_HOST' => '127.0.0.1:18865', 'REMOTE_ADDR' => '127.0.0.1', 'INDY_LISTENER_PORT' => '18865']);
        $body = json_encode($this->body(changes: ['image' => base64_encode(hex2bin(self::JPEG))]), JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:18865'];
        $client->request('POST', '/api/community', server: $headers, content: $body); self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/session'); self::assertResponseIsSuccessful(); $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->request('POST', '/api/community', server: [...$headers, 'HTTP_X_MANAGER_TOKEN' => $token], content: $body); self::assertResponseIsSuccessful();
        self::assertSame('first-moment', json_decode($client->getResponse()->getContent(), true)['event_id']);
        $client->request('GET', '/api/community/media?event=first-moment'); self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg'); self::assertSame(hex2bin(self::JPEG), $client->getResponse()->getContent());
        $client->request('GET', '/api/community/export'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type', 'application/zip');
        self::assertStringContainsString('attachment', $client->getResponse()->headers->get('Content-Disposition'));
        $client->request('GET', '/api/community/story?chapter=0'); self::assertResponseIsSuccessful(); self::assertSame('landscape-v1-mountains', json_decode($client->getResponse()->getContent(), true)['events'][0]['scene']['blueprint']);
    }
}
