<?php
declare(strict_types=1);
namespace App\Integration;
final class DiscordRateLimit extends \DomainException
{
    public function __construct(public readonly int $seconds)
    { parent::__construct('Discord begrenzt die Anfragen. Frühester neuer Versuch in '.$seconds.' Sekunden.'); }
}
