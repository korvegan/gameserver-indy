<?php
declare(strict_types=1);
namespace App\Integration;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use App\Storage\UnicodeText;

final class Connections
{
    public const MAX_AGE = 900;
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly SliceRuntime $runtime, private readonly HostNetworkProvider $provider) {}
    public static function ipv4(mixed $value, bool $public = false): ?string
    {
        return \App\Storage\IPv4::address($value, $public);
    }
    public function details(string $id, ?\stdClass $config = null): array
    {
        $profile = $this->registry->get($id); $schema = $this->games->schema($profile->game); $adapter = $this->games->adapter($profile->game);
        $config ??= $adapter->decode(PrivateFiles::read($this->registry->configPath($profile)));
        $metadata = $schema->connection ?? new \stdClass(); $mode = $metadata->mode ?? 'server_browser';
        $port = $config->{$metadata->port_key ?? ''} ?? null; $port = is_int($port) && $port >= 1 && $port <= 65535 ? $port : null;
        $bind = $config->{$metadata->bind_key ?? ''} ?? '0.0.0.0';
        $host = InstanceRegistry::publicHost($profile->connection->public_host ?? '');
        $address = $mode === 'ip_query' && $port !== null && $host !== '' && ($bind === '0.0.0.0' || self::ipv4($bind)) ? $host.':'.$port : null;
        $name = mb_substr(UnicodeText::trim(preg_replace('/[\p{Z}\x09-\x0d\x1c-\x1f\x{0085}]+/u', ' ', $adapter->title($config))), 0, 160);
        $instructions = $metadata->instructions ?? 'Im Spiel die Serverliste öffnen und nach dem Servernamen suchen.';
        $lines = [$schema->label.' · Mitspielen', 'Servername: '.$name];
        if ($address !== null) { $lines[] = 'Adresse: `'.$address.'`'; }
        $lines[] = $instructions; $lines[] = 'Ein benötigtes Passwort separat teilen; es wird hier nicht mitgesendet.';
        return ['mode' => $mode, 'port' => $port, 'public_host' => $host, 'public_address' => $address, 'server_name' => $name,
            'instructions' => $instructions, 'share_message' => implode("\n", $lines), 'password_hint' => $metadata->password_hint ?? null];
    }
    private function path(): string { return $this->runtime->directory.'/data/host-network.json'; }
    public function refresh(): array
    {
        try { $raw = $this->provider->collect(); } catch (\Throwable) { $raw = []; }
        $values = is_array($raw['lan'] ?? null) ? array_slice($raw['lan'], 0, 64) : [];
        $lan = array_values(array_unique(array_filter(array_map(self::ipv4(...), $values)))); sort($lan, SORT_STRING);
        $snapshot = (object) ['lan' => $lan, 'public' => self::ipv4($raw['public'] ?? null, true),
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'collected' => microtime(true)];
        PrivateFiles::directory(dirname($this->path())); PrivateFiles::write($this->path(), PrivateFiles::json($snapshot));
        return ['message' => 'Windows-Beitrittsdaten geprüft.'];
    }
    public function view(string $id): array
    {
        $details = $this->details($id); $profile = $this->registry->get($id); $schema = $this->games->schema($profile->game);
        $config = $this->games->adapter($profile->game)->decode(PrivateFiles::read($this->registry->configPath($profile)));
        $bind = $config->{$schema->connection->bind_key ?? ''} ?? '0.0.0.0';
        try { $snapshot = PrivateFiles::object(PrivateFiles::read($this->path(), 16384)); } catch (\Throwable) { $snapshot = new \stdClass(); }
        $age = microtime(true) - (is_numeric($snapshot->collected ?? null) ? (float) $snapshot->collected : 0);
        $fresh = $age >= 0 && $age < self::MAX_AGE; $port = $details['port']; $direct = $details['mode'] === 'ip_query' && $port !== null;
        $local = $direct && in_array($bind, ['0.0.0.0', '127.0.0.1'], true) ? '127.0.0.1:'.$port : null;
        $lan = [];
        foreach (is_array($snapshot->lan ?? null) ? $snapshot->lan : [] as $address) {
            if ($direct && $fresh && self::ipv4($address) && ($bind === '0.0.0.0' || $bind === $address)) { $lan[] = $address.':'.$port; }
        }
        $external = $direct && $fresh && $lan && self::ipv4($snapshot->public ?? null, true) ? $snapshot->public.':'.$port : null;
        return [...$details, 'local' => $local, 'lan' => $lan, 'external' => $details['public_address'] ?? $external, 'external_ip' => $external,
            'checking' => false, 'stale' => isset($snapshot->collected) && !$fresh, 'checked_at' => $snapshot->checked_at ?? null, 'external_verified' => false];
    }
}
