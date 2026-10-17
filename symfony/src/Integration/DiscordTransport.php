<?php
declare(strict_types=1);
namespace App\Integration;
interface DiscordTransport
{
    public function send(string $url, string $message): string;
    public function edit(string $url, string $messageId, string $message): string;
    public function card(string $url,array $payload,?string $jpeg=null,?string $messageId=null): string;
}
