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
        if (!in_array($driver, ['simulated', 'windows'], true) || !in_array($role, ['owner', 'viewer'], true)) {
            throw new \RuntimeException('Ungültiger isolierter Laufzeitmodus.');
        }
        $this->directory = rtrim($stateDirectory, '/\\');
    }

    public function initialize(): void
    {
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
        $marker = $this->directory.'/.indy-symfony-slice';
        if (!is_file($marker) || file_get_contents($marker) !== 'isolated-slice-v1') {
            throw new \RuntimeException('Isolierten Durchstich zuerst mit slice:init initialisieren.');
        }
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
