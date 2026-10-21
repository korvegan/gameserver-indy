<?php
declare(strict_types=1);

namespace App\Runtime;

/** Local prototype state. It never imports manager.local.json or production data. */
final class SliceRuntime
{
    public const SERVERS = ['demo-a' => 'Testserver Nord', 'demo-b' => 'Testserver Süd'];
    public readonly string $directory;

    public function __construct(string $stateDirectory, public readonly string $driver, public readonly string $role)
    {
        if (!in_array($driver, ['simulated', 'windows', 'managed'], true) || !in_array($role, ['owner', 'viewer'], true)) {
            throw new \RuntimeException('Ungültiger isolierter Laufzeitmodus.');
        }
        $this->directory = rtrim($stateDirectory, '/\\');
    }

    public function initialize(): void
    {
        if (is_file($this->directory.'/.indy-managed-state')) {
            $this->assertInitialized();
            foreach (['locks','native'] as $folder) { \App\Storage\PrivateFiles::directory($this->directory.'/'.$folder); }
            return;
        }
        if (is_link($this->directory)) {
            throw new \RuntimeException('Der Testdatenordner darf kein Link sein.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Testdatenordner nicht anlegbar.');
        }
        $marker = $this->directory.'/.indy-symfony-slice';
        if (!is_file($marker)) {
            if (count(scandir($this->directory) ?: []) !== 2) {
                throw new \RuntimeException('Nur einen leeren eigenen Testdatenordner initialisieren.');
            }
            $this->write($marker, 'isolated-slice-v1');
        }
        $this->assertInitialized();
        foreach (['locks', 'native', 'simulated'] as $folder) {
            if (!is_dir($this->directory.'/'.$folder)) {
                mkdir($this->directory.'/'.$folder, 0700);
            }
        }
    }

    public function assertInitialized(): void
    {
        if (is_file($this->directory.'/.indy-managed-state')) {
            $state = \App\Storage\PrivateFiles::object(\App\Storage\PrivateFiles::read($this->directory.'/.indy-managed-state',4096));
            if ($this->driver !== 'managed' || ($state->format ?? null) !== 'gameserver-indy-state' || ($state->version ?? null) !== 1
                || ($state->access_layout ?? null) !== 'shared-data') { throw new \RuntimeException('Unbekannte produktive Datenablage.'); }
            return;
        }
        $marker = $this->directory.'/.indy-symfony-slice';
        if (!is_file($marker) || file_get_contents($marker) !== 'isolated-slice-v1') {
            throw new \RuntimeException('Isolierten Durchstich zuerst mit slice:init initialisieren.');
        }
    }

    public function accessDirectory(): string
    {
        $this->assertInitialized();
        return $this->directory.(is_file($this->directory.'/.indy-managed-state') ? '/data' : '');
    }

    public function server(string $id): string
    {
        if (!array_key_exists($id, self::SERVERS)) {
            throw new \InvalidArgumentException('Unbekanntes Testprofil.');
        }
        return self::SERVERS[$id];
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            throw new \RuntimeException('Testdatei konnte nicht gespeichert werden.');
        }
        chmod($path, 0600);
    }
}
