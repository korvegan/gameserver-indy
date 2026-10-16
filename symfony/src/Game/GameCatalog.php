<?php
declare(strict_types=1);
namespace App\Game;

use App\Storage\PrivateFiles;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class GameCatalog
{
    /** @var array<string, ConfigurationAdapter> */
    private array $adapters = [];
    private array $schemas = [];
    public function __construct(#[AutowireIterator('indy.game_adapter')] iterable $adapters, private readonly string $projectDirectory)
    {
        foreach ($adapters as $adapter) {
            $id = $adapter->id();
            if (!self::identifier($id) || isset($this->adapters[$id])) { throw new \DomainException('Spieladapter benötigen eindeutige IDs.'); }
            $schema = PrivateFiles::object(PrivateFiles::read(dirname($this->projectDirectory).'/profiles/'.$id.'.json'));
            $this->validateSchema($id, $schema);
            $this->schemas[$id] = $schema; $this->adapters[$id] = $adapter;
        }
    }
    public static function identifier(mixed $id, int $maximum = 80): bool
    {
        return is_string($id) && strlen($id) <= $maximum && preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $id) === 1;
    }
    public function adapter(string $id): ConfigurationAdapter { return $this->adapters[$id] ?? throw new \InvalidArgumentException('Für dieses Spiel ist kein Serveradapter eingerichtet.'); }
    public function schema(string $id): \stdClass { $this->adapter($id); return $this->schemas[$id]; }
    private function validateSchema(string $id, \stdClass $schema): void
    {
        if (($schema->id ?? null) !== $id) { throw new \DomainException('Profil-ID und Adapter passen nicht zusammen.'); }
        foreach (['label', 'executable', 'config_filename', 'default_directory'] as $key) {
            if (!is_string($schema->{$key} ?? null) || trim($schema->{$key}) === '') { throw new \DomainException('Unvollständiges Spielprofil.'); }
        }
        foreach (['executable', 'config_filename'] as $key) {
            if (strpbrk($schema->{$key}, '/\\') !== false || in_array($schema->{$key}, ['.', '..'], true)) { throw new \DomainException('Spieldateinamen müssen im Installationsordner liegen.'); }
        }
        if (!is_array($schema->fields ?? null)) { throw new \DomainException('Formularfelder müssen eine Liste sein.'); }
        $seen = [];
        foreach ($schema->fields as $field) {
            if (!$field instanceof \stdClass || !is_string($field->key ?? null) || $field->key === ''
                || isset($seen[$field->key]) || !is_string($field->label ?? null)
                || !in_array($field->type ?? null, ['text', 'number', 'select', 'checkbox', 'password'], true)
                || (property_exists($field, 'path') && (!is_array($field->path) || !$field->path || array_filter($field->path, fn ($key) => !is_string($key) || $key === '')))
                || ($field->type === 'select' && (!($field->options ?? null) instanceof \stdClass || !get_object_vars($field->options)))) {
                throw new \DomainException('Ungültiges Formularschema.');
            }
            $seen[$field->key] = true;
        }
        if (property_exists($schema, 'external_launcher') && !is_bool($schema->external_launcher)) { throw new \DomainException('Ungültige Starterfähigkeit.'); }
        if (!in_array($schema->connection->mode ?? 'server_browser', ['ip_query', 'server_browser'], true)) { throw new \DomainException('Ungültiger Beitrittsmodus.'); }
    }
    public function entries(array $additional = []): array
    {
        $games = [];
        foreach ($this->schemas as $id => $schema) { $games[$id] = ['id' => $id, 'label' => $schema->label]; }
        foreach ($additional as $entry) {
            if (!$entry instanceof \stdClass || !self::identifier($entry->id ?? null) || isset($games[$entry->id])
                || !is_string($entry->label ?? null) || mb_strlen(trim($entry->label)) < 1 || mb_strlen(trim($entry->label)) > 80) { throw new \InvalidArgumentException('Katalogspiele benötigen eindeutige IDs und Namen.'); }
            $games[$entry->id] = ['id' => $entry->id, 'label' => trim($entry->label)];
        }
        $result = [];
        foreach ($games as $id => $entry) {
            $hosted = isset($this->adapters[$id]); $capabilities = [];
            foreach (['process_control', 'readiness', 'world_backup', 'update_detection', 'players', 'mods'] as $name) {
                $capabilities[$name] = ['state' => 'unknown', 'reason' => 'In Symfony noch nicht für reale Instanzen freigegeben.'];
            }
            $capabilities['configuration'] = ['state' => $hosted ? 'supported' : 'unsupported',
                'reason' => $hosted ? 'Vorhandener Konfigurationsadapter; Schreiben benötigt bestätigten Prozesszustand.' : 'Kein Dedicated-Server-Adapter eingerichtet.'];
            if (!$hosted) { $capabilities['process_control'] = ['state' => 'unsupported', 'reason' => 'Kein Prozessadapter eingerichtet.']; }
            $result[] = [...$entry, 'server_adapter' => $hosted, 'capabilities' => $capabilities,
                'schema' => $hosted ? $this->schemas[$id] : null];
        }
        return $result;
    }
}
