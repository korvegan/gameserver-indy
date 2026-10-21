<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\Principal;
use App\Access\SessionKeys;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Steam-only OpenID 2.0 direct verification; never a general identity-provider proxy. */
final class SteamIdentity
{
    public const ENDPOINT='https://steamcommunity.com/openid/login';
    private const NS='http://specs.openid.net/auth/2.0';
    public function __construct(private readonly MemberProfiles $members,private readonly SliceRuntime $runtime,
        private readonly SessionKeys $keys,private readonly HttpClientInterface $client) {}
    private function folder(): string { return $this->runtime->directory.'/data/steam-link-requests'; }
    public function begin(Principal $actor,string $scope,string $origin): array
    {
        $member=$this->members->own($actor);
        if (!$member) { throw new \DomainException('Zuerst ein persönliches Mitgliederprofil freigeben lassen.'); }
        if (isset($member['steam_id'])) { throw new \DomainException('Vor einem Kontowechsel die bisherige Steam-Verknüpfung trennen.'); }
        PrivateFiles::directory($this->folder());
        foreach (glob($this->folder().'/*.json') ?: [] as $old) { if (filemtime($old)<time()-600) { @unlink($old); } }
        $state=bin2hex(random_bytes(16)); $cookie=bin2hex(random_bytes(32)); $return=$origin.'/steam/callback?state='.$state;
        PrivateFiles::write($this->folder().'/'.$state.'.json',json_encode(['actor'=>$actor->claim(),
            'session'=>hash('sha256',$this->keys->token($actor,$scope)),'member'=>$member['id'],'generation'=>$member['link_generation'],
            'cookie'=>hash('sha256',$cookie),'return_to'=>$return,'created'=>time()],JSON_THROW_ON_ERROR));
        return ['url'=>self::ENDPOINT.'?'.http_build_query(['openid.ns'=>self::NS,'openid.mode'=>'checkid_setup',
            'openid.return_to'=>$return,'openid.realm'=>$origin.'/','openid.identity'=>self::NS.'/identifier_select',
            'openid.claimed_id'=>self::NS.'/identifier_select'],'','&',PHP_QUERY_RFC3986),'cookie'=>$cookie];
    }
    /** PHP normalizes dots in query names. Parse the raw bounded query to preserve OpenID keys. */
    public static function query(string $raw): array
    {
        if (strlen($raw)>16384) { throw new \InvalidArgumentException('Steam-Antwort zu groß.'); }
        $values=[];
        foreach (explode('&',$raw) as $pair) {
            [$key,$value]=array_pad(explode('=',$pair,2),2,''); $key=urldecode($key); $value=urldecode($value);
            if (($key!=='state' && !preg_match('/^openid\.[a-z_]+$/D',$key)) || array_key_exists($key,$values)) { throw new \InvalidArgumentException('Mehrdeutige Steam-Antwort.'); }
            $values[$key]=$value;
        }
        return $values;
    }
    public function complete(Principal $actor,string $scope,string $origin,string $rawQuery,string $cookie): void
    {
        $values=self::query($rawQuery); $state=$values['state'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/D',$state)) { throw new \DomainException('Steam-Anmeldung zuerst im eigenen Profil beginnen.'); }
        $lock=(new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('steam-link:'.$state); $lock->acquire(true);
        try {
            $path=$this->folder().'/'.$state.'.json';
            if (!is_file($path)) { throw new \DomainException('Steam-Anmeldung abgelaufen oder bereits verwendet. Bitte neu beginnen.'); }
            $request=json_decode(PrivateFiles::read($path,4096),true,flags:JSON_THROW_ON_ERROR);
            if ($request['actor']!==$actor->claim() || !hash_equals($request['session'],hash('sha256',$this->keys->token($actor,$scope)))
                || !hash_equals($request['cookie'],hash('sha256',$cookie)) || time()-$request['created']>600 || $request['created']>time()
                || $request['return_to']!==$origin.'/steam/callback?state='.$state) { throw new \DomainException('Steam-Rücksprung gehört nicht zu dieser persönlichen Sitzung.'); }
            unlink($path); // A checked request is consumed before the external verification call.
        } finally { $lock->release(); }
        $member=$this->members->own($actor);
        if (!$member || $member['id']!==$request['member'] || $member['link_generation']!==$request['generation']) { throw new \DomainException('Mitgliederzuordnung wurde geändert. Bitte neu beginnen.'); }
        $identity=$values['openid.claimed_id'] ?? '';
        $required=['op_endpoint','claimed_id','identity','return_to','response_nonce','assoc_handle'];
        $signed=explode(',',$values['openid.signed'] ?? '');
        if (($values['openid.ns'] ?? '')!==self::NS || ($values['openid.mode'] ?? '')!=='id_res'
            || ($values['openid.op_endpoint'] ?? '')!==self::ENDPOINT || ($values['openid.return_to'] ?? '')!==$request['return_to']
            || ($values['openid.identity'] ?? '')!==$identity || array_diff($required,$signed)
            || !preg_match('~^https?://steamcommunity\.com/openid/id/([0-9]{17})$~D',$identity,$match)) { throw new \DomainException('Steam-Anmeldung wurde nicht bestätigt.'); }
        $nonce=$values['openid.response_nonce'] ?? '';
        try { $stamp=ChronicleTime::parse(substr($nonce,0,20))->getTimestamp(); } catch (\Throwable) { $stamp=0; }
        if (strlen($nonce)<=20 || abs(time()-$stamp)>300) { throw new \DomainException('Steam-Antwort ist veraltet. Bitte neu beginnen.'); }
        unset($values['state']); $values['openid.mode']='check_authentication'; $response=null;
        try {
            $response=$this->client->request('POST',self::ENDPOINT,['body'=>http_build_query($values,'','&',PHP_QUERY_RFC3986),
                'headers'=>['Content-Type'=>'application/x-www-form-urlencoded'],'max_redirects'=>0,'timeout'=>12,'max_duration'=>12,'buffer'=>false]);
            if ($response->getStatusCode()!==200) { throw new \DomainException('Steam-Bestätigung derzeit nicht erreichbar. Bitte neu beginnen.'); }
            $body=''; foreach ($this->client->stream($response) as $chunk) { $body.=$chunk->getContent(); if (strlen($body)>4096) { throw new \DomainException('Ungültige Steam-Bestätigung.'); } }
            $answer=[];
            foreach (explode("\n",trim($body)) as $line) {
                [$key,$value]=array_pad(explode(':',rtrim($line,"\r"),2),2,'');
                if (isset($answer[$key])) { throw new \DomainException('Mehrdeutige Steam-Bestätigung.'); } $answer[$key]=$value;
            }
            if (($answer['ns'] ?? '')!==self::NS || ($answer['is_valid'] ?? '')!=='true') { throw new \DomainException('Steam hat die Kontozuordnung nicht bestätigt.'); }
        } catch (\DomainException $error) { throw $error; }
        catch (\Throwable) { throw new \DomainException('Steam-Bestätigung unterbrochen. Bitte neu beginnen.'); }
        finally { $response?->cancel(); }
        $this->members->steam($actor,$match[1],$request['generation']);
    }
}
