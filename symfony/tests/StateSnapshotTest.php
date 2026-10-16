<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Community\PlatformDatabase;
use App\Runtime\SliceRuntime;
use App\Runtime\StateSnapshot;
use App\Storage\PrivateFiles;
use App\WhatsApp\DeliveryStore;
use App\WhatsApp\WhatsApp;
use Symfony\Component\Process\Process;

final class StateSnapshotTest extends SliceTestCase
{
    private string $source;
    private int $port;
    private int $family;
    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY!=='Linux') { self::markTestSkipped('Erste vollständige Datenübernahme unter WSL/Linux.'); }
        static::bootKernel();
        $this->source=$this->directory.'/legacy';
        $runtime=new SliceRuntime($this->source,'simulated','owner'); $runtime->initialize();
        $access=new AccessStore($runtime); $access->initialize();
        $access->saveUser(new Principal('local-owner','owner'),['login'=>'owner@example.test','name'=>'Own owner','role'=>'owner','server_ids'=>null,'enabled'=>true],['aska']);
        (new PlatformDatabase($runtime))->initialize();
        (new SessionKeys($runtime))->token(new Principal('local-owner','owner'),'local-owner');
        // The legacy access/key layout is data/, whereas unmarked own slice fixtures use their root.
        foreach (['access.sqlite3','session-key'] as $file) { rename($this->source.'/'.$file,$this->source.'/data/'.$file); }
        $this->port=$this->freePort(); do { $this->family=$this->freePort(); } while ($this->family===$this->port);
        PrivateFiles::write($this->source.'/data/access-settings.json',json_encode((object) ['enabled'=>false,'hostname'=>'','port'=>$this->family]));
        $profile=(object) ['id'=>'aska','game'=>'aska','label'=>'Own ASKA','server_directory'=>$this->source.'/own-install',
            'config_path'=>$this->source.'/own-install/server properties.txt','launcher_script'=>$this->source.'/own-launcher.ps1',
            'future_profile_option'=>(object) ['keep'=>true]];
        PrivateFiles::directory($profile->server_directory);
        PrivateFiles::write($profile->config_path,"serverName=Own fixture\r\npassword=synthetic\r\n");
        PrivateFiles::write($this->source.'/manager.local.json',PrivateFiles::json((object) ['schema_version'=>2,'servers'=>[$profile], 'future_option'=>(object) []]));
        (new DeliveryStore($runtime))->initialize();
        PrivateFiles::write($this->source.'/data/whatsapp/settings.json',PrivateFiles::json((object) ['group_id'=>'12345@g.us','group_label'=>'Own group','enabled'=>true,'server_ids'=>['aska'],'revision'=>3]));
        PrivateFiles::write($this->source.'/data/whatsapp/credentials.json','{"api_key":"'.str_repeat('a',64).'"}');
        PrivateFiles::directory($this->source.'/data/whatsapp/sessions'); PrivateFiles::write($this->source.'/data/whatsapp/sessions/never-copy','private session standin');
        PrivateFiles::directory($this->source.'/data/servers/aska/logs'); PrivateFiles::write($this->source.'/data/servers/aska/logs/server.log','active runner standin');
        PrivateFiles::directory($this->source.'/data/world-backups/own-backup'); PrivateFiles::write($this->source.'/data/world-backups/own-backup/world.zip','opaque own archive standin');
        $db=DeliveryStore::open($this->source.'/data/whatsapp/deliveries.sqlite3');
        foreach (['sent','sending','uncertain'] as $state) { $db->prepare('INSERT INTO deliveries VALUES (?,?,?,?,?)')->execute(['aska',$state,'12345@g.us',$state,'2026-10-16T00:00:00Z']); }
        $db=null;
    }
    private function freePort(): int
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0'); $port=(int) substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket); return $port;
    }
    private function snapshot(): StateSnapshot { return static::getContainer()->get(StateSnapshot::class); }
    public function testVerifiedOfflinePackagePreservesClaimsKeysConfigAndOpaqueBackupsWithoutActivatingOrCopyingSessions(): void
    {
        $before=hash_file('sha256',$this->source.'/data/whatsapp/deliveries.sqlite3');
        $original=PrivateFiles::read($this->source.'/manager.local.json');
        $target=$this->directory.'/package'; $result=$this->snapshot()->copy($this->source,$target,$this->port);
        self::assertTrue($result['verified']); self::assertSame(1,$result['profiles']);
        self::assertSame($original,PrivateFiles::read($target.'/evidence/manager.local.json'));
        $settings=PrivateFiles::object(PrivateFiles::read($target.'/state/manager.local.json'));
        self::assertInstanceOf(\stdClass::class,$settings->future_option); self::assertTrue($settings->servers[0]->future_profile_option->keep);
        self::assertSame($this->source.'/data/servers/aska/logs',$settings->servers[0]->diagnostic_directory);
        self::assertSame(PrivateFiles::read($this->source.'/data/session-key'),PrivateFiles::read($target.'/state/data/session-key'));
        self::assertSame('opaque own archive standin',PrivateFiles::read($target.'/state/data/world-backups/own-backup/world.zip'));
        self::assertSame(PrivateFiles::read($this->source.'/own-install/server properties.txt'),PrivateFiles::read($target.'/evidence/configurations/aska.bin'));
        self::assertFileDoesNotExist($target.'/state/.indy-managed-state'); self::assertFileDoesNotExist($target.'/state/slice.sqlite');
        self::assertDirectoryDoesNotExist($target.'/state/data/whatsapp/sessions'); self::assertDirectoryDoesNotExist($target.'/state/data/servers/aska/logs');
        self::assertSame($before,hash_file('sha256',$this->source.'/data/whatsapp/deliveries.sqlite3'));
        $db=DeliveryStore::open($target.'/state/data/whatsapp/deliveries.sqlite3'); self::assertSame(['sending','sent','uncertain'],$db->query('SELECT state FROM deliveries ORDER BY state')->fetchAll(\PDO::FETCH_COLUMN)); $db=null;
        $manifest=PrivateFiles::object(PrivateFiles::read($target.'/manifest.json'));
        foreach ($manifest->hashes as $relative=>$hash) { self::assertSame($hash,hash_file('sha256',$target.'/'.$relative),$relative); }
        // A later explicit installer can mark the own working copy. Python sees the same new data.
        PrivateFiles::write($target.'/state/.indy-managed-state','{"format":"gameserver-indy-state","version":1,"access_layout":"shared-data"}');
        $python=new Process([getenv('PYTHON') ?: 'python3','-c',
            'from pathlib import Path; import sys; from gameserver_manager.runtime_state import validate_prepared_state; from gameserver_manager.application import Application; from gameserver_manager.registry import ServerRegistry; from gameserver_manager.sessions import signing_token; root=Path(sys.argv[1]); validate_prepared_state(root); app=Application(ServerRegistry(root=root)); assert app.access.principal("owner@example.test").role=="owner"; assert len(signing_token(root,"local-owner"))==64; app.close()',
            $target.'/state'],dirname(__DIR__,2));
        $python->mustRun(); self::assertSame(0,$python->getExitCode());
    }
    public function testBusyOriginalPortAndLifetimeLeaseRefuseBeforeCreatingAnyPackage(): void
    {
        $target=$this->directory.'/package'; $socket=stream_socket_server('tcp://127.0.0.1:'.$this->port);
        try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Busy port accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); }
        finally { fclose($socket); }
        $lease=fopen($this->source.'/.indy-controller.lock','c+b'); flock($lease,LOCK_EX);
        try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Busy lease accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); }
        finally { flock($lease,LOCK_UN); fclose($lease); }
    }
    public function testUnknownSchemaRemovesOnlyTheNewPackageAndKeepsOriginalBytes(): void
    {
        $file=$this->source.'/data/whatsapp/deliveries.sqlite3'; $db=DeliveryStore::open($file); $db->exec('PRAGMA user_version=99'); $db=null;
        $before=hash_file('sha256',$file); $target=$this->directory.'/package';
        try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Unknown schema accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame($before,hash_file('sha256',$file)); }
    }
    public function testExistingTargetOverlapAndSymfonyStateAreNeverTreatedAsLegacyCopies(): void
    {
        foreach ([$this->source.'/child',$this->directory] as $target) {
            try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Overlap or existing target accepted.'); }
            catch (\DomainException) { self::assertFileExists($this->source.'/manager.local.json'); }
        }
        PrivateFiles::write($this->source.'/.indy-managed-state','{"version":1}');
        $this->expectException(\DomainException::class); $this->snapshot()->copy($this->source,$this->directory.'/package',$this->port);
    }
    public function testMissingPrivateCredentialsAndLinkedDataRejectWithoutLeavingAPackage(): void
    {
        $credentials=$this->source.'/data/whatsapp/credentials.json'; $bytes=PrivateFiles::read($credentials); unlink($credentials);
        $target=$this->directory.'/package';
        try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Missing credentials accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertFileDoesNotExist($credentials); }
        PrivateFiles::write($credentials,$bytes);
        symlink($this->source.'/data/session-key',$this->source.'/data/linked-key');
        try { $this->snapshot()->copy($this->source,$target,$this->port); self::fail('Linked file accepted.'); }
        catch (\DomainException) { self::assertDirectoryDoesNotExist($target); self::assertSame($bytes,PrivateFiles::read($credentials)); }
    }
}
