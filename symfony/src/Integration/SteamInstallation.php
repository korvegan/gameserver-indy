<?php
declare(strict_types=1);
namespace App\Integration;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\NativePaths;
use App\Storage\PrivateFiles;

/** Installed build evidence never identifies the build of a running process. */
final class SteamInstallation
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly GameCatalog $games, private readonly NativePaths $paths) {}
    public function read(string $id): array
    {
        $profile = $this->registry->get($id); $app = $this->games->schema($profile->game)->steam_server_app_id;
        $directory = $this->paths->local($profile->server_directory);
        $candidates = ($profile->steam_manifest ?? '') !== '' ? [$this->paths->local($profile->steam_manifest)]
            : [dirname($directory, 2).'/appmanifest_'.$app.'.acf', $directory.'/steamapps/appmanifest_'.$app.'.acf'];
        $found = array_values(array_filter($candidates, is_file(...)));
        if (count($found) !== 1) { throw new \DomainException('Kein eindeutig zugeordnetes Steam-Appmanifest gefunden.'); }
        $path = $found[0]; $data = SteamKeyValues::named(PrivateFiles::read($path, SteamKeyValues::LIMIT), 'AppState');
        if (($data['appid'] ?? null) !== (string) $app) { throw new \DomainException('Das Steam-Appmanifest gehört zu einer anderen App.'); }
        $folder = $data['installdir'] ?? null;
        if (!is_string($folder) || $folder === '') { throw new \DomainException('Im Steam-Appmanifest fehlt der Installationsordner.'); }
        $client = strtolower(basename(dirname($path))) === 'steamapps'
            && $this->paths->identity(dirname($path).'/common/'.$folder) === $this->paths->identity($directory);
        $command = $this->paths->identity(dirname($path)) === $this->paths->identity($directory.'/steamapps')
            && ($folder === basename($directory) || $this->paths->identity($this->paths->local($folder)) === $this->paths->identity($directory));
        if (!$client && !$command) { throw new \DomainException('Steam-Appmanifest und Serverordner stimmen nicht überein.'); }
        $user = $data['userconfig'] ?? []; $mounted = $data['mountedconfig'] ?? [];
        if (!is_array($user) || !is_array($mounted)) { throw new \DomainException('Der installierte Steam-Branch ist nicht lesbar.'); }
        $branch = SteamKeyValues::branch($user['betakey'] ?? $mounted['betakey'] ?? 'public');
        if (!empty($mounted['betakey']) && SteamKeyValues::branch($mounted['betakey']) !== $branch
            || isset($profile->steam_branch) && SteamKeyValues::branch($profile->steam_branch) !== $branch) { throw new \DomainException('Steam-Branchwechsel ist nicht abgeschlossen oder passt nicht zum Profil.'); }
        $build = $data['buildid'] ?? null;
        if (!SteamKeyValues::build($build)) { throw new \DomainException('Das Steam-Appmanifest enthält keine installierte Build-ID.'); }
        $number = static function (mixed $value): int {
            if (!is_string($value) || !preg_match('/^[+]?[0-9]{1,18}$/D', $value)) { throw new \DomainException('Der Steam-Installationszustand ist nicht lesbar.'); }
            return (int) $value;
        };
        $flags = $number($data['stateflags'] ?? '-1');
        if ($flags >= 4294967296) { throw new \DomainException('Der Steam-Installationszustand ist nicht lesbar.'); }
        $incomplete = false;
        foreach ([['bytestodownload','bytesdownloaded'], ['bytestostage','bytesstaged']] as [$total,$done]) { $incomplete = $number($data[$total] ?? '0') > $number($data[$done] ?? '0') || $incomplete; }
        return ['app_id' => $app, 'branch' => $branch, 'build' => $build, 'updated' => $number($data['lastupdated'] ?? '0'),
            'busy' => (bool) ($flags & (256|512|1024|131072|262144|524288|1048576|2097152|4194304|8388608)) || $incomplete,
            'complete' => in_array($flags, [4,68], true) && in_array($data['targetbuildid'] ?? '', ['', '0', $build], true)];
    }
}
