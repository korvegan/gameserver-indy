<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Access\AccessStore;
use App\Access\Principal;
use App\Backup\WorldBackups;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Kernel;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

$root = $argv[1] ?? ''; $input = PrivateFiles::object(PrivateFiles::read($root.'/fixture.json'));
if (!str_starts_with(basename($root), 'indy-backup-parity-') || ($input->marker ?? null) !== basename($root)) { throw new RuntimeException('Own parity fixture required.'); }
$state = $root.'/php-state'; (new SliceRuntime($state, 'managed', 'owner'))->initialize();
foreach (['APP_ENV' => 'test', 'SLICE_STATE_DIR' => $state, 'SLICE_DRIVER' => 'managed', 'SLICE_ROLE' => 'owner', 'INDY_CACHE_DIR' => $root.'/cache'] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value; putenv($name.'='.$value);
}
Clock::set(new MockClock()); $kernel = new Kernel('test', true); $kernel->boot(); $container = $kernel->getContainer()->get('test.service_container');
$container->get(AccessStore::class)->initialize(); $container->get(JobRepository::class)->initialize();
$gateway = new class implements ProcessGateway {
    public function call(string $serverId, string $action): array {
        if ($action !== 'Status') { throw new RuntimeException('Parity fixture cannot start processes.'); }
        return ['known' => true, 'running' => false, 'starting' => false, 'launcher_running' => false, 'processes' => [], 'pid' => null, 'ready' => null];
    }
};
$container->set(ProcessGateway::class, $gateway); $owner = new Principal('local-owner', 'owner');
$container->get(InstanceRegistry::class)->register($owner, (object) ['id' => 'fixture', 'game' => 'enshrouded', 'label' => 'Parity', 'server_directory' => $input->install]);
$container->get(ProcessObservation::class)->record('fixture', $gateway->call('fixture', 'Status')); $container->get(RuntimeLifecycle::class)->open();
$folder = $state.'/data/world-backups/fixture'; PrivateFiles::directory($folder);
foreach (['zip', 'json'] as $extension) { if (!copy($input->archive.'.'.$extension, $folder.'/'.$input->id.'.'.$extension)) { throw new RuntimeException('Copy failed'); } }
$service = $container->get(WorldBackups::class); $service->execute($owner, 'fixture', 'backup_verify', (object) ['backup_id' => $input->id]);
$preview = $service->preview($owner, 'fixture', $input->id);
$service->execute($owner, 'fixture', 'backup_restore', (object) ['backup_id' => $input->id, 'token' => $preview['token'], 'confirmation' => 'WIEDERHERSTELLEN']);
$created = $service->execute($owner, 'fixture', 'backup');
$container->get(\Doctrine\DBAL\Connection::class)->close(); $kernel->shutdown();
echo json_encode(['archive' => $folder.'/'.$created['backup_id'], 'id' => $created['backup_id']], JSON_THROW_ON_ERROR);
