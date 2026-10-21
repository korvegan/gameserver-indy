<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessPolicy;
use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Community\ChronicleReader;
use App\Community\ChronicleWriter;
use App\Community\MemberProfiles;
use App\Community\PlatformDatabase;
use App\Community\SteamIdentity;
use App\Runtime\SliceRuntime;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MemberProfilesTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testInvitedProfileKeepsHistoryAndSteamLinkIsVerifiedOnceForSameMember(): void
    {
        static::bootKernel(); $container=static::getContainer(); $this->ledger(); $container->get(PlatformDatabase::class)->initialize();
        $owner=new Principal('local-owner','owner'); $access=$container->get(AccessStore::class); $profiles=$container->get(MemberProfiles::class);
        $access->configure($owner,['enabled'=>true,'hostname'=>'own.test.ts.net','port'=>18877]);
        $grant=$access->saveUser($owner,['login'=>'member@example.test','name'=>'Own reader','role'=>'viewer','server_ids'=>[]],[]);
        $member=$profiles->save($owner,(object)['name'=>'Erster Name','principals'=>['local-owner',$grant['id']],'role'=>'writer','enabled'=>true]);
        $reader=$access->principal('member@example.test');
        $container->get(AccessPolicy::class)->require($reader,'community.write'); self::assertFalse($reader->allows('server.stop'));
        $writer=$container->get(ChronicleWriter::class); $id=$member['member_id'];
        $body=(object)['id'=>'own-member-moment','revision'=>1,'title'=>'Unser Moment','game_id'=>'enshrouded','members'=>[],
            'participant_ids'=>[$id],'badge'=>'Gemeinsam','campaign'=>'Eigene Runde'];
        $writer->save($reader,$body); $writer->save($reader,$body);
        $profiles->rename($owner,'Neuer Name'); $event=$container->get(ChronicleReader::class)->detail($owner,'own-member-moment')['event'];
        self::assertSame([$id],$event['member_ids']); self::assertSame('Erster Name',$event['member_names']->{$id});
        self::assertSame($reader->id,$event['actor_id']); self::assertSame($id,$event['author_member_id']); self::assertCount(1,$event['awards']);
        self::assertSame(1,$container->get(ChronicleReader::class)->page($owner)['total']);
        $calls=0;
        $steam=new SteamIdentity($profiles,$container->get(SliceRuntime::class),$container->get(SessionKeys::class),new MockHttpClient(function($method,$url,$options) use (&$calls) {
            ++$calls; self::assertSame('POST',$method); self::assertSame(SteamIdentity::ENDPOINT,$url);
            self::assertStringContainsString('openid.mode=check_authentication',$options['body']);
            return new MockResponse("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n");
        }));
        $request=$steam->begin($owner,'local-owner','http://127.0.0.1:18865');
        $start=SteamIdentity::query(parse_url($request['url'],PHP_URL_QUERY));
        $return=$start['openid.return_to']; parse_str(parse_url($return,PHP_URL_QUERY),$state);
        $query=http_build_query(['state'=>$state['state'],'openid.ns'=>'http://specs.openid.net/auth/2.0','openid.mode'=>'id_res',
            'openid.op_endpoint'=>SteamIdentity::ENDPOINT,'openid.return_to'=>$return,'openid.response_nonce'=>gmdate('Y-m-d\TH:i:s\Z').'own',
            'openid.identity'=>'https://steamcommunity.com/openid/id/76561198000000000','openid.claimed_id'=>'https://steamcommunity.com/openid/id/76561198000000000',
            'openid.assoc_handle'=>'own','openid.sig'=>'synthetic','openid.signed'=>'op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle']);
        $steam->complete($owner,'local-owner','http://127.0.0.1:18865',$query,$request['cookie']);
        self::assertSame('76561198000000000',$profiles->own($owner)['steam_id']); self::assertSame(1,$calls);
        try { $steam->complete($owner,'local-owner','http://127.0.0.1:18865',$query,$request['cookie']); self::fail('Replayed link accepted'); }
        catch (\DomainException) { self::assertSame(1,$calls); }
        $profiles->steam($owner,null); self::assertArrayNotHasKey('steam_id',$profiles->own($owner));
        self::assertSame('unknown',$profiles->view($owner)['members'][0]['steam_data_state']);
    }
    public function testWrongBrowserReturnCannotLink(): void
    {
        static::bootKernel(); $container=static::getContainer(); $this->ledger(); $owner=new Principal('local-owner','owner');
        $profiles=$container->get(MemberProfiles::class);
        $profiles->save($owner,(object)['name'=>'Own profile','principals'=>['local-owner'],'role'=>'writer','enabled'=>true]);
        $client=new MockHttpClient(function() { self::fail('Invalid browser must not contact Steam'); });
        $steam=new SteamIdentity($profiles,$container->get(SliceRuntime::class),$container->get(SessionKeys::class),$client);
        $request=$steam->begin($owner,'local-owner','http://127.0.0.1:18865'); $start=SteamIdentity::query(parse_url($request['url'],PHP_URL_QUERY));
        parse_str(parse_url($start['openid.return_to'],PHP_URL_QUERY),$state);
        $this->expectException(\DomainException::class);
        $steam->complete($owner,'local-owner','http://127.0.0.1:18865','state='.$state['state'],'wrong-browser');
    }
    public function testMemberCanBeRetiredAfterPersonalAccessWasRevoked(): void
    {
        static::bootKernel(); $this->ledger(); $container=static::getContainer(); $owner=new Principal('local-owner','owner');
        $access=$container->get(AccessStore::class); $profiles=$container->get(MemberProfiles::class);
        $value=['login'=>'own-retired@example.test','name'=>'Retired','role'=>'viewer','server_ids'=>[],'enabled'=>true];
        $grant=$access->saveUser($owner,$value,[]);
        $profile=(object)['name'=>'Own member','principals'=>[$grant['id']],'role'=>'writer','enabled'=>true];
        $member=$profiles->save($owner,$profile);
        $access->saveUser($owner,[...$value,'id'=>$grant['id'],'revision'=>1,'enabled'=>false],[]);
        $profile->id=$member['member_id']; $profile->enabled=false;
        $profiles->save($owner,$profile);
        self::assertFalse($profiles->view($owner)['members'][0]['enabled']);
        self::assertFalse($profiles->view($owner)['accounts'][1]['enabled']);
    }
}
