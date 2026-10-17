<?php
declare(strict_types=1);
namespace App\Integration;
final class NexusModSource implements ModSource
{
    public function id(): string { return 'nexus'; }
    public function label(): string { return 'Nexus Mods'; }
    public function gameKey(string $key): string { return ModLinks::slug($key); }
    public function capabilities(string $game): array { return ModLinks::manual('Persönliche Downloadbestätigungen und gegebenenfalls Premium bleiben beim Spieler. Nexus-Schlüssel werden hier nicht gesammelt.'); }
    public function link(string $key,string $url): array
    {
        $this->gameKey($key); $p=ModLinks::page($url); parse_str($p['query'] ?? '',$q);
        if (!in_array($p['host'],['www.nexusmods.com','nexusmods.com'],true) || !preg_match('~^/'.preg_quote($key,'~').'/mods/([1-9][0-9]*)/?$~D',$p['path'] ?? '',$m)
            || array_diff(array_keys($q),['tab','file_id']) || isset($q['tab']) && $q['tab']!=='files' || isset($p['fragment'])
            || isset($q['file_id']) && !is_string($q['file_id'])) { throw new \InvalidArgumentException('Nexus-Modseite der zugeordneten Spielkennung verwenden; keine persönlichen Downloadlinks.'); }
        $file=isset($q['file_id']) ? ModLinks::number($q['file_id']) : null;
        return ['mod_id'=>ModLinks::number($m[1]),'file_id'=>$file,'url'=>'https://www.nexusmods.com/'.$key.'/mods/'.$m[1].($file ? '?tab=files&file_id='.$file : '')];
    }
}
