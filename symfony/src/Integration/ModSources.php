<?php
declare(strict_types=1);
namespace App\Integration;
use App\Game\GameCatalog;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ModSources
{
    private array $sources = [];
    public function __construct(#[AutowireIterator('indy.mod_source')] iterable $sources)
    {
        foreach ($sources as $source) {
            if (!GameCatalog::identifier($source->id()) || isset($this->sources[$source->id()])) { throw new \DomainException('Modquellen benötigen eindeutige Kennungen.'); }
            $this->sources[$source->id()] = $source;
        }
    }
    public function capabilities(string $game): array
    {
        $result = [];
        foreach ($this->sources as $id => $source) {
            $capabilities = $source->capabilities($game); $public = [];
            foreach (['discover', 'download', 'install', 'distribute'] as $name) {
                $value = $capabilities[$name] ?? ['state' => 'unknown', 'reason' => 'Für dieses Spiel nicht geprüft.'];
                if (!in_array($value['state'] ?? null, ['supported', 'unsupported', 'unknown'], true) || !is_string($value['reason'] ?? null) || $value['reason'] === '') { throw new \DomainException('Unvollständige Fähigkeit der Modquelle.'); }
                $public[$name] = ['state' => $value['state'], 'reason' => $value['reason']];
            }
            $result[] = ['id' => $id, 'capabilities' => $public];
        }
        return $result;
    }
}
