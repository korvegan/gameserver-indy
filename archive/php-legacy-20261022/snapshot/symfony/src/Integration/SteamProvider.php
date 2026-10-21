<?php
declare(strict_types=1);
namespace App\Integration;
interface SteamProvider { public function query(int $appId, string $branch): string; }
