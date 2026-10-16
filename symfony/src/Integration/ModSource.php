<?php
declare(strict_types=1);
namespace App\Integration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Discovery, entitlement and installation are separate provider capabilities. */
#[AutoconfigureTag('indy.mod_source')]
interface ModSource
{
    public function id(): string;
    /** Per game: discover/download/install/distribute => state + reason. No account credentials. */
    public function capabilities(string $game): array;
}
