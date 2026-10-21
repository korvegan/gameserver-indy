<?php
declare(strict_types=1);
require __DIR__.'/dashboard_bootstrap.php';
$kernel = dashboardKernel(); $request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request,$response);
