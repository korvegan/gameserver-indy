<?php
declare(strict_types=1);
namespace App\Log;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Storage\PrivateFiles;

final class GameLogSource
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games, private readonly NativePaths $paths) {}
    public function path(string $id): ?string
    {
        $profile = $this->registry->get($id);
        if ($profile->game === 'aska') { return $this->registry->diagnosticPath($profile).'/server.log'; }
        if ($profile->game !== 'enshrouded') { return null; }
        $config = $this->games->adapter($profile->game)->decode(PrivateFiles::read($this->registry->configPath($profile)));
        $directory = ($profile->log_directory ?? '') ?: ($config->logDirectory ?? './logs');
        if (!str_starts_with($directory, '/') && !preg_match('~^[a-z]:[/\\\\]|^\\\\\\\\~i', $directory)) {
            $directory = rtrim($profile->server_directory, '/\\').'/'.$directory;
        }
        return $this->paths->local($directory).'/enshrouded_server.log';
    }
}
