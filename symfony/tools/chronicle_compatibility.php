<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Access\AccessStore;
use App\Access\Principal;
use App\Community\ChronicleExport;
use App\Community\ChronicleMedia;
use App\Community\ChronicleReader;
use App\Community\ChronicleSnapshot;
use App\Community\ChronicleValues;
use App\Community\ChronicleWriter;
use App\Community\PlatformDatabase;
use App\Instance\InstanceRegistry;
use App\Kernel;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

$root = $argv[1] ?? ''; $input = PrivateFiles::object(PrivateFiles::read($root.'/fixture.json'));
if (!str_starts_with(basename($root), 'indy-chronicle-parity-') || ($input->marker ?? null) !== basename($root)) { throw new RuntimeException('Own chronicle fixture required.'); }
$state = $root.'/php-state'; (new SliceRuntime($state, 'simulated', 'owner'))->initialize();
foreach (['APP_ENV' => 'test', 'SLICE_STATE_DIR' => $state, 'SLICE_DRIVER' => 'simulated', 'SLICE_ROLE' => 'owner', 'INDY_CACHE_DIR' => $root.'/cache'] as $name => $value) { $_ENV[$name] = $_SERVER[$name] = $value; putenv($name.'='.$value); }
$kernel = new Kernel('test', true); $kernel->boot(); $container = $kernel->getContainer()->get('test.service_container'); $owner = new Principal('local-owner', 'owner');
if (($argv[2] ?? '') === 'race') {
    $body = clone $input->race; $body->title = $argv[3];
    try { $container->get(ChronicleWriter::class)->save($owner, $body); echo 'committed'; }
    catch (DomainException) { echo 'conflict'; }
    $kernel->shutdown(); exit;
}
$store = $container->get(AccessStore::class); $store->initialize();
$store->configure($owner, ['enabled' => true, 'hostname' => 'indy.private.ts.net']);
$store->saveUser($owner, ['login' => 'relative@example.invalid', 'name' => 'Relative', 'role' => 'family', 'server_ids' => ['aska']], ['aska', 'enshrouded']);
$family = $store->principal('relative@example.invalid'); $container->get(InstanceRegistry::class)->import($input->settings);
$snapshot = $container->get(ChronicleSnapshot::class)->copy($input->database, $root.'/copy-package');
PrivateFiles::directory($state.'/data'); if (!copy($root.'/copy-package/platform.sqlite3', $state.'/data/platform.sqlite3')) { throw new RuntimeException('Own copy failed.'); }
$reader = $container->get(ChronicleReader::class); $writer = $container->get(ChronicleWriter::class); $database = $container->get(PlatformDatabase::class); $database->initialize();
$before = $database->read(fn (PDO $db) => $db->query('SELECT * FROM community_events ORDER BY rowid')->fetchAll());
$views = [];
foreach ($input->queries as $query) { $views[] = $reader->page($query->scope === 'family' ? $family : $owner, get_object_vars($query->query)); }
$story = $reader->story($owner, ['chapter' => '0']); $familyStory = $reader->story($family, ['chapter' => '1']);
foreach ($input->retries as $body) { $writer->save($owner, $body); }
$after = $database->read(fn (PDO $db) => $db->query('SELECT * FROM community_events ORDER BY rowid')->fetchAll());
if ($before !== $after) { throw new RuntimeException('Historical replay rewrote stored evidence.'); }
foreach ($input->corrections as $body) { $writer->save($owner, $body); }
$times = [];
foreach ($input->time_values as $value) {
    try { $occurred = ChronicleValues::occurred($value); } catch (Throwable) { $occurred = null; }
    $times[] = ['read' => ChronicleValues::time($value, '2026-01-01T00:00:00Z'), 'occurred' => $occurred];
}
$writer->save($owner, $input->new); $archive = $container->get(ChronicleExport::class)->archive($owner); PrivateFiles::write($root.'/php-export.zip', $archive);
$media = $container->get(ChronicleMedia::class)->get($owner, 'original', '2'); $newEvent = $reader->detail($owner, $input->new->id)['event']; $kernel->shutdown();
echo json_encode(['views' => $views, 'story' => $story, 'family_story' => $familyStory, 'snapshot' => $snapshot, 'times' => $times,
    'media_sha256' => hash('sha256', $media), 'new_event' => $newEvent], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
