<?php
declare(strict_types=1);
namespace App\Integration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Discovery, entitlement and installation are separate provider capabilities. */
#[AutoconfigureTag('indy.mod_source')]
interface ModSource
{
    public function id(): string;
    public function label(): string;
    /** Validate the provider's game identity (not the dedicated-server App ID). */
    public function gameKey(string $key): string;
    /** Canonical original page and stable provider identifiers; no network access. */
    public function link(string $key,string $url): array;
    /** Per game: discover/download/install/distribute => state + reason. No account credentials. */
    public function capabilities(string $game): array;
}
