<?php
declare(strict_types=1);
namespace App\Log;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Integration\ManagerSettings;
use App\Runtime\ProcessObservation;
use App\Storage\PrivateFiles;

final class ServerLogs
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games,
        private readonly NativePaths $paths, private readonly ManagerSettings $settings, private readonly ProcessObservation $observations) {}
    public function read(string $id, ?string $source = null): array
    {
        if (!in_array($source, [null, 'server', 'launcher'], true)) { throw new \InvalidArgumentException('Unbekannte Protokollquelle.'); }
        $profile = $this->registry->get($id); $schema = $this->games->schema($profile->game); $broken = false;
        $status = $this->observations->view($id);
        try { $config = $this->games->adapter($profile->game)->decode(PrivateFiles::read($this->registry->configPath($profile))); }
        catch (\Throwable) { $config = new \stdClass(); $broken = true; }
        $webhook = getenv('DISCORD_WEBHOOK_URL') ?: ($this->settings->read()->discord->webhook_url ?? '');
        $values = LogText::secrets($config, is_string($webhook) ? $webhook : ''); $owned = $this->registry->diagnosticPath($profile).'/server.log';
        $server = is_file($owned) ? $owned : null;
        if ($server === null && $source !== 'launcher') {
            if (($profile->log_directory ?? '') !== '') { $directory = $this->paths->local($profile->log_directory); }
            elseif (isset($schema->windows_log_directory)) {
                $home = $status['user_profile'] ?? null;
                $directory = is_string($home) ? $this->paths->local($home).'/'.$schema->windows_log_directory : null;
            } else {
                $directory = $config->logDirectory ?? './logs';
                if (!str_starts_with($directory, '/') && !preg_match('~^[a-z]:[/\\\\]|^\\\\\\\\~i', $directory)) { $directory = rtrim($profile->server_directory, '/\\').'/'.$directory; }
                $directory = $this->paths->local($directory);
            }
            $files = [];
            if (isset($directory) && is_dir($directory)) {
                foreach (new \DirectoryIterator($directory) as $file) {
                    if ($file->isFile() && in_array(strtolower($file->getExtension()), ['log','txt'], true)
                        && (!isset($schema->log_files) || in_array($file->getFilename(), $schema->log_files, true))) { $files[] = $file->getPathname(); }
                }
            }
            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a)); $server = $files[0] ?? null;
        }
        $launcher = $this->registry->diagnosticPath($profile).'/launcher.log'; $launcher = is_file($launcher) ? $launcher : null;
        $selected = $source ?? ($server !== null ? 'server' : ($launcher !== null ? 'launcher' : 'server')); $path = $selected === 'server' ? $server : $launcher;
        $process = count($status['processes'] ?? []) === 1 ? (array) $status['processes'][0] : [];
        return ['source' => $selected, 'sources' => [['id' => 'server', 'label' => 'Spielserver'], ['id' => 'launcher', 'label' => 'Starter / Startfehler']],
            'server_id' => $id, 'profile_label' => $profile->label, 'read_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'process_started_at' => $process['started'] ?? null,
            'file' => $path !== null ? basename($path) : null, 'updated_at' => $path !== null ? gmdate('Y-m-d\TH:i:s\Z', filemtime($path)) : null,
            'text' => $path !== null ? ($broken && $selected === 'server' ? 'Die Konfiguration ist nicht lesbar; das Serverprotokoll kann deshalb gerade nicht bereinigt werden.' : LogText::tail($path, $values, 4000))
                : ($selected === 'server' ? 'Noch keine Serverlogs vorhanden.' : 'Noch kein Starterprotokoll vorhanden. Der nächste Start über das Dashboard wird hier protokolliert.')];
    }
}
