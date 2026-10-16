<?php
declare(strict_types=1);
namespace App\WhatsApp;
interface WhatsAppClient
{
    public function configured(): bool;
    public function session(): array;
    public function connect(): void;
    public function qr(): string;
    public function groups(): array;
    public function send(string $groupId, string $text): void;
    public function logout(): void;
}
