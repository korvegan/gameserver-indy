<?php
declare(strict_types=1);
namespace App\Tests;
use App\Access\Principal;
use App\Integration\ModCatalog;
use App\Integration\ModSources;
use App\Instance\InstanceRegistry;

final class ModCatalogTest extends SliceTestCase
{
    #[\PHPUnit\Framework\Attributes\Group('regular')]
    public function testOwnerCombinesOriginalSourcesWithoutDownloadsOrDedicatedAdapter(): void
    {
        static::bootKernel(); $this->ledger(); $c=static::getContainer(); $owner=new Principal('local-owner','owner');
        $registry=$c->get(InstanceRegistry::class);
        $registry->register($owner,(object)['id'=>'fixture','game'=>'enshrouded','label'=>'Eigener Test','server_directory'=>$this->directory.'/game']);
        $registry->addGame($owner,(object)['id'=>'raft','label'=>'Raft']);
        self::assertFalse(array_values(array_filter($registry->catalog($owner)['games'],fn($game)=>$game['id']==='raft'))[0]['server_adapter']);
        $catalog=$c->get(ModCatalog::class);
        $sources=[['steam-workshop','648800','https://steamcommunity.com/sharedfiles/filedetails/?id=12345'],
            ['nexus','raft','https://www.nexusmods.com/raft/mods/21?tab=files&file_id=34'],
            ['raftmodding','raft','https://www.raftmodding.com/mods/test-fixture'],
            ['website','raft','https://example.org/mods/test-fixture']];
        foreach ($sources as [$source,$key,$url]) {
            $catalog->save($owner,'raft',(object)['action'=>'source','source'=>$source,'game_key'=>$key]);
            $catalog->save($owner,'raft',(object)['action'=>'item','source'=>$source,'url'=>$url,'name'=>'Gleicher Anzeigename','version'=>'1.2',
                'loader'=>'RML 2.8.0','sha256'=>str_repeat('a',64),'target'=>'both','dependencies'=>[],'order'=>1]);
        }
        $view=$catalog->view($owner,'raft'); self::assertCount(4,$view['bindings']); self::assertCount(4,$view['items']);
        self::assertCount(4,array_unique(array_keys($view['items'])));
        $nexus=array_values(array_filter($view['items'],fn($row)=>$row['source']==='nexus'))[0]; self::assertSame('34',$nexus['file_id']);
        foreach ($view['sources'] as $source) { self::assertSame('unsupported',$source['capabilities']['install']['state']); self::assertSame('manual',$source['steps']['metadata']); }
        self::assertSame(4,count($c->get(ModSources::class)->capabilities('raft')));
    }
    public function testNexusPersonalDownloadLinkIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \App\Integration\NexusModSource())->link('raft','https://www.nexusmods.com/raft/mods/21?key=personal-secret');
    }
}
