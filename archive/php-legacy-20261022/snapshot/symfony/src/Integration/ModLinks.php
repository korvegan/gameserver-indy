<?php
declare(strict_types=1);
namespace App\Integration;

/** Link imports only. Original downloads stay in the player's own browser. */
final class ModLinks
{
    public static function page(string $url): array
    {
        $p=parse_url(trim($url));
        if (strlen($url)>1200 || !$p || ($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port'])
            || !str_contains($p['host'],'.') || filter_var($p['host'],FILTER_VALIDATE_IP) || preg_match('/[\x00-\x20\x7f]/',$url)) {
            throw new \InvalidArgumentException('Öffentliche HTTPS-Modseite ohne Zugangsdaten verwenden.');
        }
        return $p;
    }
    public static function number(string $value): string
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D',$value)) { throw new \InvalidArgumentException('Numerische Spiel-/Dateikennung erforderlich.'); }
        return $value;
    }
    public static function slug(string $value): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D',$value)) { throw new \InvalidArgumentException('Spielkennung der Modseite prüfen.'); }
        return $value;
    }
    public static function manual(string $reason): array
    {
        return ['discover'=>['state'=>'supported','reason'=>'Originalseite manuell öffnen und konkrete Modseite hinzufügen.'],
            'download'=>['state'=>'unsupported','reason'=>'Download beim Anbieter mit eigenem Zugang. Kein automatischer Download auf diesen Server.'],
            'install'=>['state'=>'unsupported','reason'=>$reason],
            'distribute'=>['state'=>'supported','reason'=>'Quellenreferenzen teilen; Moddateien werden nicht weitergegeben.']];
    }
}
