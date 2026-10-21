<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Access\AccessStore;
use App\Storage\PrivateFiles;

/** Also fence a pre-lease Python process by reserving both original listener ports. */
final class LegacyLease
{
    public function run(string $source,int $port,callable $action): mixed
    {
        if (PHP_OS_FAMILY!=='Linux' || !is_dir($source) || is_link($source)) { throw new \DomainException('Gestoppten lokalen WSL-Altbestand verwenden.'); }
        $settings=AccessStore::configuration(json_decode(PrivateFiles::read($source.'/data/access-settings.json',16384),true,flags:JSON_THROW_ON_ERROR));
        $ports=[$port,$settings['port']];
        if ($ports[0]===$ports[1] || min($ports)<1024 || max($ports)>65535) { throw new \DomainException('Getrennte gültige Originalports erforderlich.'); }
        $path=$source.'/.indy-controller.lock';
        if (is_link($path) || is_file($path) && stat($path)['nlink']!==1) { throw new \DomainException('Unabhängige lokale Controllersperre erforderlich.'); }
        $lease=fopen($path,'c+b');
        if ($lease===false || !flock($lease,LOCK_EX|LOCK_NB)) {
            if (is_resource($lease)) { fclose($lease); } throw new \DomainException('Die ursprüngliche Verwaltung arbeitet noch.');
        }
        chmod($path,0600); $listeners=[];
        try {
            foreach ($ports as $number) {
                $socket=@stream_socket_server('tcp://127.0.0.1:'.$number,$errno,$error);
                if ($socket===false) { throw new \DomainException('Ursprüngliche Verwaltung zuerst geordnet beenden.'); }
                $listeners[]=$socket;
            }
            return $action();
        } finally { foreach ($listeners as $listener) { fclose($listener); } flock($lease,LOCK_UN); fclose($lease); }
    }
}
