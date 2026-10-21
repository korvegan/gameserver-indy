<?php
declare(strict_types=1);
namespace App\Game;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A game format, independent of installations, campaigns, providers or process state. */
#[AutoconfigureTag('indy.game_adapter')]
interface ConfigurationAdapter
{
    public function id(): string;
    public function title(\stdClass $configuration): string;
    public function decode(string $bytes): \stdClass;
    public function encode(\stdClass $configuration, string $original): string;
    public function validate(\stdClass $configuration): void;
    public function merge(\stdClass $submitted, \stdClass $previous): \stdClass;
    public function publicConfiguration(\stdClass $configuration): \stdClass;
    public function validateEdit(\stdClass $configuration, \stdClass $previous, \stdClass $profile): void;
    public function validateStart(\stdClass $configuration): void;
    public function initial(): \stdClass;
}
