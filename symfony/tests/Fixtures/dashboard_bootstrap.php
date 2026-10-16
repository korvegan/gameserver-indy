<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/DashboardServices.php';

function dashboardKernel(): App\Kernel
{
    $fixture = new App\Tests\Fixtures\DashboardServices(getenv('INDY_DASHBOARD_FIXTURE_ROOT') ?: '');
    $kernel = new App\Kernel('test',false); $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    foreach ([App\Runtime\ProcessGateway::class,App\Integration\SteamProvider::class,
        App\Integration\HostNetworkProvider::class,App\Integration\DiscordTransport::class] as $id) { $container->set($id,$fixture); }
    $container->set(App\WhatsApp\WhatsAppClient::class,new App\Tests\Fixtures\DashboardWhatsApp($fixture));
    $container->set(App\Runtime\RuntimeSupervisor::class,new App\Runtime\RuntimeSupervisor(
        $container->get(App\Runtime\SliceRuntime::class),$container->get(App\Runtime\RuntimeSettings::class),
        $container->get(App\Runtime\RuntimeLifecycle::class),$container->get(App\Access\AccessStore::class),
        $container->get(App\Integration\Discord::class),$container->get(App\WhatsApp\DeliveryStore::class),
        $container->get(App\Community\CommunityLedger::class),$fixture->root.'/release','test'));
    return $kernel;
}
