<?php
declare(strict_types=1);
namespace App\WhatsApp;
final class WhatsAppError extends \DomainException
{
    public function __construct(public readonly ?int $status = null)
    {
        parent::__construct('Der lokale WhatsApp-Dienst antwortet nicht wie erwartet. Verbindung im Dashboard prüfen.');
    }
}
