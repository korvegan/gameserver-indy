<?php
declare(strict_types=1);
namespace App\Integration;
interface HostNetworkProvider { public function collect(): array; }
