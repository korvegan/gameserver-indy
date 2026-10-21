<?php
declare(strict_types=1);
namespace App\Integration;

use App\Instance\NativePaths;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SteamCmd implements SteamProvider
{
    public function __construct(private readonly ManagerSettings $settings, private readonly NativePaths $paths, private readonly SliceRuntime $runtime) {}
    public static function arguments(string $executable, int $appId): array
    {
        if (!in_array($appId, [2278520, 3246670], true)) { throw new \InvalidArgumentException('Keine freigegebene Steam-Server-App.'); }
        return [$executable, '+login', 'anonymous', '+app_info_update', '1', '+app_info_print', (string) $appId, '+quit'];
    }
    public function query(int $appId, string $branch): string
    {
        $branch = SteamKeyValues::branch($branch); $this->runtime->assertInitialized();
        $configured = $this->settings->read()->steamcmd_path ?? '';
        if ($configured !== '') { $this->paths->absolute($configured); $executable = $this->paths->local($configured); }
        else { $finder = new ExecutableFinder(); $executable = $finder->find('steamcmd') ?? $finder->find('steamcmd.exe'); }
        if (!$executable || !is_file($executable)) { throw new \DomainException('SteamCMD ist nicht eingerichtet oder die lokale Datei fehlt.'); }
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('steam-provider');
        if (!$lock->acquire()) { throw new \DomainException('Eine andere Steam-Abfrage läuft. Bitte später erneut prüfen.'); }
        try {
            $text = ''; $process = new Process(self::arguments($executable, $appId), dirname($executable));
            $process->setTimeout(45)->disableOutput();
            // Process callbacks need output enabled; consume and clear each chunk to bound memory.
            $process->enableOutput();
            try {
                $process->run(function (string $type, string $chunk) use (&$text, $process): void {
                    if (strlen($text) + strlen($chunk) > SteamKeyValues::LIMIT) { $process->stop(0); throw new \DomainException('Steam-Metadaten sind zu groß.'); }
                    $text .= $chunk; $process->clearOutput(); $process->clearErrorOutput();
                });
            } catch (\Throwable) { throw new \DomainException('Steam-Abfrage fehlgeschlagen oder nach 45 Sekunden abgebrochen. Kein bestätigter neuer Stand.'); }
            if (!$process->isSuccessful() || !preg_match('/Connecting anonymously[^\r\n]*\.\.\.OK/', $text)) { throw new \DomainException('SteamCMD hat keine erfolgreiche Online-Abfrage bestätigt.'); }
            $data = SteamKeyValues::named($text, (string) $appId); $selected = $data['depots']['branches'][mb_convert_case($branch, MB_CASE_FOLD, 'UTF-8')] ?? null;
            if (!is_array($selected) || ($selected['pwdrequired'] ?? '0') !== '0' || !SteamKeyValues::build($selected['buildid'] ?? null)) { throw new \DomainException('Für diesen Steam-Branch fehlen zugängliche Build-Metadaten.'); }
            return $selected['buildid'];
        } finally { $lock->release(); }
    }
}
