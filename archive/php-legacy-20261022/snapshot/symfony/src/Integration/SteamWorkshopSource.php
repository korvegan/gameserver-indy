<?php
declare(strict_types=1);
namespace App\Integration;
final class SteamWorkshopSource implements ModSource
{
    public function id(): string { return 'steam-workshop'; }
    public function label(): string { return 'Steam Workshop'; }
    public function gameKey(string $key): string { return ModLinks::number($key); }
    public function capabilities(string $game): array { return ModLinks::manual('Workshop- und Dedicated-Unterstützung müssen für dieses Spiel geprüft werden. Eine Item-ID bestätigt keine Installation oder Besitzrechte.'); }
    public function link(string $key,string $url): array
    {
        $this->gameKey($key); $p=ModLinks::page($url); parse_str($p['query'] ?? '',$q);
        if ($p['host']!=='steamcommunity.com' || !in_array($p['path'] ?? '',['/sharedfiles/filedetails/','/workshop/filedetails/'],true)
            || array_diff(array_keys($q),['id']) || !is_string($q['id'] ?? null) || isset($p['fragment'])) { throw new \InvalidArgumentException('Workshop-Mod- oder Sammlungsseite mit Item-ID verwenden.'); }
        $id=ModLinks::number($q['id']);
        return ['mod_id'=>$id,'file_id'=>null,'url'=>'https://steamcommunity.com/sharedfiles/filedetails/?id='.$id];
    }
}
