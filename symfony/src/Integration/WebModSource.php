<?php
declare(strict_types=1);
namespace App\Integration;
final class WebModSource implements ModSource
{
    public function id(): string { return 'website'; }
    public function label(): string { return 'Weitere Modseite'; }
    public function gameKey(string $key): string { return ModLinks::slug($key); }
    public function capabilities(string $game): array { return ModLinks::manual('Nur ursprüngliche Modseiten verknüpfen. Metadaten, Abhängigkeiten und Installation werden manuell gepflegt.'); }
    public function link(string $key,string $url): array
    {
        $this->gameKey($key); $p=ModLinks::page($url);
        if (isset($p['query']) || isset($p['fragment'])) { throw new \InvalidArgumentException('Dauerhafte Modseite ohne persönliche Parameter verwenden.'); }
        $canonical='https://'.strtolower($p['host']).($p['path'] ?? '/');
        return ['mod_id'=>hash('sha256',$canonical),'file_id'=>null,'url'=>$canonical];
    }
}
