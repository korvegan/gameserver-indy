<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Community\PlatformDatabase;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use App\WhatsApp\DeliveryStore;

/** Own legacy administration; installation/configuration paths never point to real games. */
final class LegacyStateFixture
{
    public static function create(string $source): array
    {
        $runtime=new SliceRuntime($source,'simulated','owner'); $runtime->initialize();
        $access=new AccessStore($runtime); $access->initialize();
        $access->saveUser(new Principal('local-owner','owner'),['login'=>'owner@example.test','name'=>'Own owner','role'=>'owner','server_ids'=>null,'enabled'=>true],['aska']);
        (new PlatformDatabase($runtime))->initialize(); (new SessionKeys($runtime))->token(new Principal('local-owner','owner'),'local-owner');
        foreach (['access.sqlite3','session-key'] as $file) { rename($source.'/'.$file,$source.'/data/'.$file); }
        $port=self::port(); do { $family=self::port(); } while ($family===$port);
        PrivateFiles::write($source.'/data/access-settings.json',json_encode((object) ['enabled'=>false,'hostname'=>'','port'=>$family]));
        $profile=(object) ['id'=>'aska','game'=>'aska','label'=>'Own ASKA','server_directory'=>$source.'/own-install',
            'config_path'=>$source.'/own-install/server properties.txt','launcher_script'=>$source.'/own-launcher.ps1','future_profile_option'=>(object) ['keep'=>true]];
        PrivateFiles::directory($profile->server_directory); PrivateFiles::write($profile->config_path,"serverName=Own fixture\r\npassword=synthetic\r\n");
        PrivateFiles::write($source.'/manager.local.json',PrivateFiles::json((object) ['schema_version'=>2,'servers'=>[$profile],'future_option'=>(object) []]));
        (new DeliveryStore($runtime))->initialize();
        PrivateFiles::write($source.'/data/whatsapp/settings.json',PrivateFiles::json((object) ['group_id'=>'12345@g.us','group_label'=>'Own group','enabled'=>true,'server_ids'=>['aska'],'revision'=>3]));
        PrivateFiles::write($source.'/data/whatsapp/credentials.json','{"api_key":"'.str_repeat('a',64).'"}');
        PrivateFiles::directory($source.'/data/whatsapp/sessions'); PrivateFiles::write($source.'/data/whatsapp/sessions/never-copy','private session standin');
        PrivateFiles::directory($source.'/data/servers/aska/logs'); PrivateFiles::write($source.'/data/servers/aska/logs/server.log','active runner standin');
        PrivateFiles::directory($source.'/data/world-backups/own-backup'); PrivateFiles::write($source.'/data/world-backups/own-backup/world.zip','opaque own archive standin');
        $db=DeliveryStore::open($source.'/data/whatsapp/deliveries.sqlite3');
        foreach (['sent','sending','uncertain'] as $state) { $db->prepare('INSERT INTO deliveries VALUES (?,?,?,?,?)')->execute(['aska',$state,'12345@g.us',$state,'2026-10-16T00:00:00Z']); } $db=null;
        return [$source,$port,$family];
    }
    private static function port(): int
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0'); $port=(int) substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket); return $port;
    }
}
