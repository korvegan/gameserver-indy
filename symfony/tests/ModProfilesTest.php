<?php
declare(strict_types=1);
namespace App\Tests;
use App\Access\Principal;
use App\Integration\ModCatalog;
use App\Integration\ModProfiles;

final class ModProfilesTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testPublishedRevisionSurvivesCatalogEditsAndSupportsOwnManualComparison(): void
    {
        static::bootKernel(); $this->ledger(); $c=static::getContainer(); $owner=new Principal('local-owner','owner');
        $catalog=$c->get(ModCatalog::class); $profiles=$c->get(ModProfiles::class);
        $catalog->save($owner,'enshrouded',(object)['action'=>'source','source'=>'website','game_key'=>'enshrouded']);
        $item=(object)['action'=>'item','source'=>'website','url'=>'https://example.org/mod/own-test','name'=>'Own mod',
            'version'=>'1.0','file_id'=>'file-1','sha256'=>str_repeat('a',64),'loader'=>'Own Loader 1','target'=>'both','order'=>1,'dependencies'=>[]];
        $catalog->save($owner,'enshrouded',$item); $ids=array_keys($catalog->view($owner,'enshrouded')['items']);
        $publish=(object)['name'=>'Own pack','game_version'=>'1.0','loader'=>'Own Loader 1','items'=>$ids];
        $first=$profiles->publish($owner,'enshrouded',$publish)['id']; $original=$profiles->export($owner,'enshrouded',$first);
        $item->version='1.1'; $item->sha256=str_repeat('b',64); $catalog->save($owner,'enshrouded',$item);
        $second=$profiles->publish($owner,'enshrouded',$publish)['id'];
        self::assertSame($original,$profiles->export($owner,'enshrouded',$first));
        $delta=$profiles->compare($owner,'enshrouded',$second,$first); self::assertCount(1,$delta['changed']); self::assertCount(0,$delta['added']);
        $view=$profiles->view($owner,'enshrouded'); self::assertNull($view['profiles'][0]['receipt']);
        $profiles->action($owner,'enshrouded',$second,(object)['action'=>'confirm','status'=>'confirmed','sha256'=>$view['profiles'][0]['sha256']]);
        $receipt=$profiles->view($owner,'enshrouded')['profiles'][0]['receipt']; self::assertSame('manual',$receipt['type']); self::assertTrue($receipt['fresh']);
        $profiles->action($owner,'enshrouded',$first,(object)['action'=>'withdraw']);
        self::assertNotNull($profiles->view($owner,'enshrouded')['profiles'][1]['withdrawn_at']);
        self::assertSame('1.0',json_decode($original,true)['items'][0]['version']);
    }
    public function testRevisionHistoryRemainsReadableAboveOneMiB(): void
    {
        static::bootKernel(); $this->ledger(); $c=static::getContainer(); $owner=new Principal('local-owner','owner');
        \App\Storage\PrivateFiles::directory($this->directory.'/data');
        \App\Storage\PrivateFiles::write($this->directory.'/data/mod-profiles.json',json_encode(['version'=>1,'games'=>[],
            'fixture_history_padding'=>str_repeat('x',1100000)],JSON_THROW_ON_ERROR));
        self::assertSame([],$c->get(ModProfiles::class)->view($owner,'enshrouded')['profiles']);
    }
}
