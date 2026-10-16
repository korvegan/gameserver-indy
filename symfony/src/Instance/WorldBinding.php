<?php
declare(strict_types=1);
namespace App\Instance;

/** Path identity for mutation exclusivity; archive/restore remains its own service. */
final class WorldBinding
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly NativePaths $paths) {}
    public function resolve(\stdClass $profile, \stdClass $configuration, ?string $windowsProfile): array
    {
        $files = [$this->registry->configPath($profile)]; $saveId = '';
        if ($profile->game === 'enshrouded') {
            $world = str_replace('\\', '/', $configuration->saveDirectory);
            if (!str_starts_with($world, '/') && !preg_match('/^[a-z]:\//i', $world)) { $world = rtrim($profile->server_directory, '/\\').'/'.$world; }
            $world = $this->paths->local($world);
        } elseif ($profile->game === 'aska') {
            $saveId = $configuration->{'save id'} ?? '';
            if ($saveId === '') { return ['world' => null, 'files' => $files, 'save_id' => '']; }
            if ($windowsProfile === null || $windowsProfile === '') { throw new \DomainException('Das native Windows-Benutzerprofil für die ASKA-Welt ist unbekannt.'); }
            $world = rtrim($this->paths->local($windowsProfile), '/\\').'/AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_'.$saveId;
            if (($configuration->{'custom game preset path'} ?? '') !== '') { $files[] = $this->paths->local($configuration->{'custom game preset path'}); }
        } else { throw new \DomainException('Kein Weltadapter eingerichtet.'); }
        $identity = $this->paths->identity($world);
        foreach ([$this->paths->local($profile->server_directory), ...$files] as $protected) {
            $other = $this->paths->identity($protected);
            if ($identity === $other || str_starts_with($other, $identity.'/')) { throw new \DomainException('Weltordner und geschützte Installations-/Konfigurationsdateien überschneiden sich.'); }
        }
        return ['world' => $world, 'files' => $files, 'save_id' => $saveId];
    }
    public function overlaps(array $one, array $two): bool
    {
        $one = array_filter([$one['world'], ...$one['files']], fn ($path) => $path !== null);
        $two = array_filter([$two['world'], ...$two['files']], fn ($path) => $path !== null);
        foreach ($one as $first) { foreach ($two as $second) {
            $a = $this->paths->identity($first); $b = $this->paths->identity($second);
            if ($a === $b || str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/')) { return true; }
        } }
        return false;
    }
}
