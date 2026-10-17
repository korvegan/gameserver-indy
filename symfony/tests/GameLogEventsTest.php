<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\Principal;
use App\Game\EnshroudedConfiguration;
use App\Instance\InstanceRegistry;
use App\Log\GameLogIngestor;
use App\Log\GameEventJobs;
use App\Log\AskaLogParser;
use App\Runtime\RuntimeEvents;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

final class GameLogEventsTest extends SliceTestCase
{
    #[Group('regular')]
    public function testIncrementalCurrentSessionEventsProduceOneDurableJobEach(): void
    {
        $_ENV['SLICE_DRIVER'] = $_SERVER['SLICE_DRIVER'] = 'managed'; putenv('SLICE_DRIVER=managed');
        $this->ledger(); $container = static::getContainer(); $container->get(RuntimeLifecycle::class)->open();
        $directory = $this->directory.'/own-install'; PrivateFiles::directory($directory.'/logs');
        $container->get(InstanceRegistry::class)->register(new Principal('local-owner','owner'),
            (object)['id'=>'fixture','game'=>'enshrouded','label'=>'Fixture','server_directory'=>$directory]);
        self::assertFalse($container->get(GameLogIngestor::class)->ingest('fixture', ['known'=>true,'running'=>false,'processes'=>[]]), 'A new profile without configuration has no parseable log yet.');
        PrivateFiles::write($directory.'/enshrouded_server.json', PrivateFiles::json((new EnshroudedConfiguration())->initial()));
        $path = $directory.'/logs/enshrouded_server.log';
        $status = ['known'=>true,'running'=>true,'processes'=>[['pid'=>42,'started'=>'2024-01-01T10:00:00Z']]];
        PrivateFiles::write($path, "Current UTC date: 2023.12.31 10:00:00\n[Session] 'HostOnline' (up)!\nCurrent UTC date: 2024.01.01 10:00:00\nshader noise synthetic-password\n[Session] 'HostOnline' (up)");
        $logs = $container->get(GameLogIngestor::class); $events = $container->get(RuntimeEvents::class);
        self::assertFalse($logs->ingest('fixture', $status)); self::assertSame([], $events->pending());
        file_put_contents($path, "!\n[Session] 'HostOnline' (up)!\n", FILE_APPEND);
        $logs->ingest('fixture', $status); self::assertCount(1, $events->pending());
        $eventJobs = $container->get(GameEventJobs::class); $eventJobs->enqueue(); $eventJobs->enqueue();
        $db = $container->get(Connection::class);
        self::assertSame(1, (int)$db->fetchOne("SELECT COUNT(*) FROM slice_jobs WHERE operation='game_event'"));
        self::assertSame([], $events->pending());
        $logs->ingest('fixture', $status); self::assertCount(1, $events->recent('fixture'));
        // A copied rotation of the same session is not a second readiness event.
        PrivateFiles::write($path, "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n[Session] 'HostOnline' (down)!\n");
        $logs->ingest('fixture', $status); self::assertCount(2, $events->recent('fixture'));
        self::assertSame('session.closed', $events->pending()[0]['type']);
        PrivateFiles::write($path, "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n[Session] 'HostOnline' (down)!\n");
        $logs->ingest('fixture', $status); self::assertCount(2, $events->recent('fixture'), 'A full historical copy is deduplicated.');
        PrivateFiles::write($path, "[Session] 'HostOnline' (up)!\n");
        $logs->ingest('fixture', $status); self::assertCount(3, $events->recent('fixture'), 'A headerless continuation keeps its verified start binding.');
        file_put_contents($path, "[Session] 'HostOnline' (down)!\n", FILE_APPEND);
        $logs->ingest('fixture', ['known'=>true, 'running'=>false, 'processes'=>[]]);
        self::assertCount(4, $events->recent('fixture'), 'Final session closure remains readable after the process exits.');
        self::assertStringNotContainsString('synthetic-password', json_encode($db->fetchAllAssociative('SELECT * FROM runtime_events')));
    }
    #[Group('regular')]
    public function testAskaOnlyRecognizesTimestampedCurrentSessionOpening(): void
    {
        $parser = new AskaLogParser(); $context = []; $started = new \DateTimeImmutable('2024-01-01T10:00:00Z');
        self::assertNull($parser->parse('The session is now open!', $started, $context));
        self::assertNull($parser->parse('2024-01-01T09:59:59Z The session is now open!', $started, $context));
        self::assertNull($parser->parse('2024-01-01T10:00:01Z Trying to access a shader', $started, $context));
        self::assertSame('session.ready', $parser->parse('2024-01-01T10:00:01Z The session is now open!', $started, $context));
    }
}
