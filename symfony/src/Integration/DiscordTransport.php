<?php
declare(strict_types=1);
namespace App\Integration;
interface DiscordTransport { public function send(string $url, string $message): string; }
