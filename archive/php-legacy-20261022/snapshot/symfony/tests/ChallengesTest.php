<?php
declare(strict_types=1);
namespace App\Tests;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Community\Challenges;
use App\Community\ChronicleReader;
use App\Community\MemberProfiles;
use App\Community\PlatformDatabase;

final class ChallengesTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testFourMemberGoalMovesFromThreeToFourAndKeepsOneHistoricalAward(): void
    {
        static::bootKernel(); $this->ledger(); $container=static::getContainer(); $container->get(PlatformDatabase::class)->initialize();
        $owner=new Principal('local-owner','owner'); $profiles=$container->get(MemberProfiles::class); $access=$container->get(AccessStore::class); $ids=[];
        foreach (range(1,4) as $index) {
            $grant=$access->saveUser($owner,['login'=>'member'.$index.'@example.test','name'=>'Person '.$index,'role'=>'viewer','server_ids'=>[]],[]);
            $ids[]=$profiles->save($owner,(object)['name'=>'Person '.$index,'principals'=>[$grant['id']],'role'=>'writer','enabled'=>true])['member_id'];
        }
        $challenges=$container->get(Challenges::class);
        $campaign=$challenges->createCampaign($owner,(object)['name'=>'Unsere vier','game_id'=>'enshrouded','member_ids'=>$ids])['campaign_id'];
        $goal=$challenges->createGoal($owner,(object)['campaign_id'=>$campaign,'title'=>'Alle angekommen','badge'=>'Sturmklingen vereint','rule_version'=>1])['goal_id'];
        foreach (array_slice($ids,0,3) as $id) { $challenges->observe($owner,$goal,$id,'achieved'); }
        $before=$challenges->view($owner)['goals'][0]; self::assertSame(3,$before['achieved']); self::assertSame(4,$before['total']);
        self::assertNull($before['completed_at']); self::assertSame('unknown',$before['evidence'][$ids[3]]['state']);
        $reader=$container->get(ChronicleReader::class); self::assertSame(0,$reader->page($owner)['total']);
        $challenges->observe($owner,$goal,$ids[3],'achieved'); $challenges->observe($owner,$goal,$ids[3],'achieved'); $challenges->reconcile();
        $page=$reader->page($owner); self::assertSame(1,$page['total']); $event=$page['events'][0];
        self::assertCount(4,$event['awards']); self::assertCount(1,array_unique(array_column($event['awards'],'badge_id')));
        self::assertSame($ids,$event['member_ids']); self::assertSame($goal,$event['challenge_id']); self::assertSame(1,$event['rule_version']);
        self::assertSame('manual',$event['source']); self::assertSame('',$event['occurred_at']); self::assertNotEmpty($event['observed_at']);
        $profiles->save($owner,(object)['id'=>$ids[0],'name'=>'Später umbenannt','principals'=>[$access->view()['users'][0]['id']],'role'=>'writer','enabled'=>false]);
        self::assertSame('Person 1',$reader->page($owner)['events'][0]['member_names']->{$ids[0]});
        self::assertSame($ids,$challenges->campaign($owner,$campaign)['member_ids']);
        $challenges->withdraw($owner,$goal,'Die manuelle Bestätigung war falsch.');
        self::assertSame(0,$reader->page($owner)['total']); $detail=$reader->detail($owner,$event['id']);
        self::assertTrue($detail['event']['removed']); self::assertCount(2,$detail['history']);
    }
    public function testHistoricalAndUnknownEvidenceAreNotInventedSharedSessions(): void
    {
        static::bootKernel(); $this->ledger(); $container=static::getContainer(); $container->get(PlatformDatabase::class)->initialize();
        $owner=new Principal('local-owner','owner'); $member=$container->get(MemberProfiles::class)->save($owner,(object)['name'=>'Own person','principals'=>['local-owner'],'role'=>'writer','enabled'=>true])['member_id'];
        $challenges=$container->get(Challenges::class); $campaign=$challenges->createCampaign($owner,(object)['name'=>'Historie','game_id'=>'enshrouded','member_ids'=>[$member]])['campaign_id'];
        $goal=$challenges->createGoal($owner,(object)['campaign_id'=>$campaign,'title'=>'Historisches Ziel','badge'=>'Gesammelt','rule_version'=>1])['goal_id'];
        $challenges->observe($owner,$goal,$member,'private',source:'steam-import'); self::assertNull($challenges->view($owner)['goals'][0]['completed_at']);
        $challenges->observe($owner,$goal,$member,'achieved','2020-01-01T12:00:00Z',source:'steam-import');
        $event=$container->get(ChronicleReader::class)->page($owner)['events'][0]; self::assertSame('steam-import',$event['source']);
        self::assertStringStartsWith('2020-01-01',$event['occurred_at']); self::assertStringNotContainsString('2020-',$event['observed_at']);
        self::assertStringContainsString('keine gleichzeitige Spielsitzung',$event['description']);
    }
}
