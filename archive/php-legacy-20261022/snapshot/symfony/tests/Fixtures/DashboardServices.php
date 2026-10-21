<?php
declare(strict_types=1);
namespace App\Tests\Fixtures;

use App\Access\Principal;
use App\Game\AskaConfiguration;
use App\Game\EnshroudedConfiguration;
use App\Integration\Connections;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Only loaded by the separate fixture entrypoints, never by application configuration. */
final class DashboardServices implements \App\Runtime\ProcessGateway, \App\Integration\SteamProvider,
    \App\Integration\HostNetworkProvider, \App\Integration\DiscordTransport
{
    public function __construct(public readonly string $root)
    {
        $temporary = realpath(sys_get_temp_dir()); $actual = realpath($root);
        if ($actual !== $root || !str_starts_with($root, $temporary.'/indy-symfony-dashboard-')
            || file_get_contents($root.'/.dashboard-fixture') !== 'own-dashboard-fixture-v1'
            || getenv('SLICE_STATE_DIR') !== $root.'/state') { throw new \RuntimeException('Own dashboard fixture required.'); }
    }
    private function path(string $id): string
    {
        if (!in_array($id,['enshrouded','aska'],true)) { throw new \RuntimeException('No fixture process for this profile.'); }
        return $this->root.'/process-'.$id.'.json';
    }
    public function call(string $serverId,string $action): array
    {
        $file = $this->path($serverId);
        $deadline = microtime(true)+5;
        while (is_file($this->root.'/pause-native') && microtime(true)<$deadline) { usleep(10000); clearstatcache(); }
        if (is_file($this->root.'/pause-native')) { throw new \RuntimeException('Fixture gate timed out.'); }
        $state = is_file($file) ? json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR) :
            ['known'=>true,'running'=>false,'launcher_running'=>false,'starting'=>false,'ready'=>null,'pid'=>null,'processes'=>[],'user_profile'=>$this->root.'/WindowsUser'];
        if (is_file($this->root.'/unknown-status')) { return ['known'=>false,'running'=>null,'pid'=>null]; }
        if ($action === 'Start') {
            usleep(400000); $started = gmdate('Y-m-d\TH:i:s\Z'); $pid = $serverId === 'aska' ? 12002 : 12001;
            $state = [...$state,'running'=>true,'pid'=>$pid,'processes'=>[['pid'=>$pid,'started'=>$started]]];
            PrivateFiles::directory($this->root.'/library/steamapps/common/EnshroudedServer/logs');
            PrivateFiles::write($this->root.'/library/steamapps/common/EnshroudedServer/logs/enshrouded_server.log',
                'Current UTC date: '.gmdate('Y.m.d H:i:s')."\n[Session] 'HostOnline' (up)!\n");
            PrivateFiles::directory($this->root.'/state/data/servers/aska/logs');
            PrivateFiles::write($this->root.'/state/data/servers/aska/logs/server.log',$started." The session is now open!\n");
        } elseif ($action === 'Stop') { usleep(200000); $state = [...$state,'running'=>false,'pid'=>null,'processes'=>[]]; }
        elseif ($action !== 'Status') { throw new \RuntimeException('Unknown fixture action.'); }
        PrivateFiles::write($file,json_encode($state,JSON_THROW_ON_ERROR)); return $state;
    }
    public function query(int $appId,string $branch): string { usleep(200000); return $appId === 2278520 ? '200' : '300'; }
    public function collect(): array { return ['lan'=>['192.168.1.20'],'public'=>'8.8.8.8']; }
    public function configured(): bool { return true; }
    public function session(): array
    {
        $file = $this->root.'/whatsapp-state'; $state = is_file($file) ? file_get_contents($file) : 'STOPPED';
        if ($state === 'SCAN_QR_CODE') { PrivateFiles::write($file,'WORKING'); }
        return ['status'=>$state,'exists'=>true];
    }
    public function connect(): void { PrivateFiles::write($this->root.'/whatsapp-state','SCAN_QR_CODE'); }
    public function qr(): string { return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aPioAAAAASUVORK5CYII='); }
    public function groups(): array { return [['id'=>'123456789@g.us','label'=>'Browser-Gruppe'],['id'=>'987654321@g.us','label'=>'Zweite Gruppe']]; }
    // Synthetic transport receipt; no network I/O in this test service.
    public function send(string $destination,string $text): string { return '90001'; }
    public function edit(string $url,string $messageId,string $message): string { return $messageId; }
    public function card(string $url,array $payload,?string $jpeg=null,?string $messageId=null): string { return $messageId ?? '90002'; }
    public function logout(): void { PrivateFiles::write($this->root.'/whatsapp-state','STOPPED'); }

    public function seed(\App\Kernel $kernel): void
    {
        $container = $kernel->getContainer()->get('test.service_container'); $runtime = $container->get(SliceRuntime::class);
        if (is_file($runtime->directory.'/manager.local.json')) { return; }
        $profiles = [];
        foreach (['enshrouded'=>['EnshroudedServer','enshrouded_server.exe',2278520,'100'], 'aska'=>['ASKA Server','AskaServer.exe',3246670,'300']] as $id=>[$folder,$exe,$app,$build]) {
            $directory = $this->root.'/library/steamapps/common/'.$folder; PrivateFiles::directory($directory); PrivateFiles::write($directory.'/'.$exe,'fixture-placeholder');
            PrivateFiles::write($this->root.'/library/steamapps/appmanifest_'.$app.'.acf','"AppState" {"appid" "'.$app.'" "installdir" "'.$folder.'" "buildid" "'.$build.'" "StateFlags" "4"}');
            $profiles[] = (object) ['id'=>$id,'game'=>$id,'label'=>$id === 'aska' ? 'ASKA · Testwelt' : 'Enshrouded', 'server_directory'=>$directory,
                ...($id === 'enshrouded' ? ['connection'=>(object) ['public_host'=>'fixture.example.org']] : [])];
            if ($id === 'enshrouded') {
                $config = (new EnshroudedConfiguration())->initial(); $config->name = 'Symfony Indy'; $config->slotCount = 4; $config->userGroups[0]->password = 'synthetic-secret';
                PrivateFiles::write($directory.'/enshrouded_server.json',PrivateFiles::json($config));
                PrivateFiles::directory($directory.'/savegame'); PrivateFiles::write($directory.'/savegame/world0.ksd','own-browser-world');
                PrivateFiles::write($directory.'/enshrouded_server_readme.txt',"Version: 1.0.0.0\n");
            } else {
                $adapter = new AskaConfiguration(); $config = $adapter->initial(); $config->{'save id'} = 'browser'; $config->{'authentication token'} = 'synthetic-token';
                PrivateFiles::write($directory.'/server properties.txt',$adapter->encode($config,''));
                PrivateFiles::directory($this->root.'/WindowsUser/AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_browser');
                PrivateFiles::write($this->root.'/WindowsUser/AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_browser/world.dat','own-aska-world');
            }
        }
        PrivateFiles::write($runtime->directory.'/manager.local.json',PrivateFiles::json((object) ['schema_version'=>2,'servers'=>$profiles,'catalog_games'=>[(object) ['id'=>'raft','label'=>'Raft']]]));
        $container->get(Connections::class)->refresh();
        foreach (['enshrouded','aska'] as $id) { $container->get(ProcessObservation::class)->refresh($id); }
        $container->get(\App\Community\ChronicleWriter::class)->save(new Principal('local-owner','owner'),(object) [
            'id'=>'qa-milestone','revision'=>1,'game_id'=>'raft','title'=>'Gemeinsamer Testmoment','members'=>['Test Eins','Test Zwei'],'badge'=>'Gemeinsam','occurred_at'=>'']);
        // The real host predates these features. A fresh slice:init alone hid that upgrade gap.
        $database = $container->get(\Doctrine\DBAL\Connection::class);
        $database->executeStatement('DROP TABLE discord_status_cards');
        $database->executeStatement('DROP TABLE community_discord_messages');
    }
}

final class DashboardWhatsApp implements \App\WhatsApp\WhatsAppClient
{
    public function __construct(private readonly DashboardServices $fixture) {}
    public function configured(): bool { return true; }
    public function session(): array { return $this->fixture->session(); }
    public function connect(): void { $this->fixture->connect(); }
    public function qr(): string { return $this->fixture->qr(); }
    public function groups(): array { return $this->fixture->groups(); }
    public function send(string $groupId,string $text): void { $this->fixture->send($groupId,$text); }
    public function logout(): void { $this->fixture->logout(); }
}
