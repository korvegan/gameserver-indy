<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Instance\InstanceRegistry;
use App\Integration\Connections;
use App\Integration\HostNetworkProvider;
use App\Integration\SteamInstallation;
use App\Integration\SteamKeyValues;
use App\Kernel;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

$root = $argv[1] ?? ''; $input = PrivateFiles::object(PrivateFiles::read($root.'/fixture.json'));
if (!str_starts_with(basename($root), 'indy-integration-parity-') || ($input->marker ?? null) !== basename($root)) { throw new RuntimeException('Own integration fixture required.'); }
$state = $root.'/php-state'; (new SliceRuntime($state, 'simulated', 'owner'))->initialize();
foreach (['APP_ENV' => 'test', 'SLICE_STATE_DIR' => $state, 'SLICE_DRIVER' => 'simulated', 'SLICE_ROLE' => 'owner', 'INDY_CACHE_DIR' => $root.'/cache'] as $name => $value) { $_ENV[$name] = $_SERVER[$name] = $value; putenv($name.'='.$value); }
$kernel = new Kernel('test', true); $kernel->boot(); $container = $kernel->getContainer()->get('test.service_container');
$container->set(HostNetworkProvider::class, new class implements HostNetworkProvider { public function collect(): array { throw new LogicException('No network in parity fixture.'); } });
$container->get(InstanceRegistry::class)->import($input->settings); $result = [];
foreach ($input->cases as $case) {
    try {
        $value = match ($case->kind) {
            'keyvalues' => SteamKeyValues::named($case->value, 'AppState'), 'branch' => SteamKeyValues::branch($case->value),
            'ipv4' => Connections::ipv4($case->value, $case->public), 'host' => InstanceRegistry::publicHost($case->value),
            'join' => $container->get(Connections::class)->details($case->value), 'installed' => $container->get(SteamInstallation::class)->read($case->value),
        };
        $result[] = ['valid' => true, 'value' => $value];
    } catch (Throwable) { $result[] = ['valid' => false]; }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); $kernel->shutdown();
