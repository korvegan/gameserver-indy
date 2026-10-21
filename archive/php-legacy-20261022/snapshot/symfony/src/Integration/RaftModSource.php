<?php
declare(strict_types=1);
namespace App\Integration;
final class RaftModSource implements ModSource
{
    public function id(): string { return 'raftmodding'; }
    public function label(): string { return 'RaftModding'; }
    public function gameKey(string $key): string
    { if ($key!=='raft') { throw new \InvalidArgumentException('RaftModding ist Raft zugeordnet (Kennung raft).'); } return $key; }
    public function capabilities(string $game): array { return ModLinks::manual('Raft Mod Loader und aktueller Mehrspielerstand müssen getestet werden. RaftModding meldet derzeit deaktivierte Modprofile; kein automatischer Profilimport.'); }
    public function link(string $key,string $url): array
    {
        $this->gameKey($key); $p=ModLinks::page($url);
        if (!in_array($p['host'],['www.raftmodding.com','raftmodding.com'],true) || !preg_match('~^/mods/([a-z0-9][a-z0-9_-]*)/?$~D',$p['path'] ?? '',$m)
            || isset($p['query']) || isset($p['fragment'])) { throw new \InvalidArgumentException('RaftModding-Modseite verwenden.'); }
        return ['mod_id'=>$m[1],'file_id'=>null,'url'=>'https://www.raftmodding.com/mods/'.$m[1]];
    }
}
