<?php
declare(strict_types=1);
require __DIR__.'/dashboard_bootstrap.php';
$kernel = dashboardKernel();
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
if (($argv[1] ?? '') === 'fixture:seed') {
    (new App\Tests\Fixtures\DashboardServices(getenv('INDY_DASHBOARD_FIXTURE_ROOT')))->seed($kernel);
    exit(0);
}
$application->run();
