<?php
declare(strict_types=1);
namespace App\Community;

use App\Access\AccessPolicy;
use App\Access\AccessStore;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Integration\DiscordTransport;
use App\Integration\DiscordRateLimit;
use App\Integration\DiscordWebhook;
use App\Integration\ManagerSettings;
use App\Runtime\OutcomeUnknown;
use Doctrine\DBAL\Connection;

/** Explicit community publishing, separate destination and consent from server notices. */
final class DiscordPublication
{
    public function __construct(private readonly ManagerSettings $settings,private readonly AccessPolicy $policy,private readonly AccessStore $access,
        private readonly ChronicleReader $reader,private readonly ChronicleMedia $media,private readonly DiscordTransport $transport,
        private readonly Connection $db,private readonly GameCatalog $games) {}
    private function configuration(): array
    {
        $value=$this->settings->read()->community_discord ?? new \stdClass();
        $url=$value->webhook_url ?? '';
        if (!is_string($url) || $url!=='' && !DiscordWebhook::valid($url)) { throw new \DomainException('Gemeinschafts-Webhook ungültig.'); }
        $settings=$this->access->settings(); $origin=$settings['enabled'] ? 'https://'.$settings['hostname'] : null;
        $media=($value->allow_media ?? false)===true;
        return ['url'=>$url,'media'=>$media,'origin'=>$origin,'revision'=>hash('sha256',json_encode([$url,$media,$origin],JSON_THROW_ON_ERROR))];
    }
    public function view(Principal $actor,?string $eventId=null): array
    {
        $this->policy->require($actor,'community.export'); $config=$this->configuration();
        $rows=$this->db->fetchAllAssociative('SELECT event_id,remote_id,revision,state,media,template_version,attempted_at,next_try,error FROM community_discord_messages ORDER BY attempted_at DESC LIMIT 30');
        if ($eventId!==null) {
            if (!GameCatalog::identifier($eventId)) { throw new \InvalidArgumentException('Ungültige Ereignis-ID.'); }
            $row=$this->db->fetchAssociative('SELECT event_id,remote_id,revision,state,media,template_version,attempted_at,next_try,error FROM community_discord_messages WHERE event_id=?',[$eventId]);
            if ($row && !in_array($eventId,array_column($rows,'event_id'),true)) { $rows[]=$row; }
        }
        foreach ($rows as &$row) { $row['generation']=$this->generation($row['event_id']); }
        return ['configured'=>$config['url']!=='' && $config['origin']!==null,'allow_media'=>$config['media'],'private_origin_configured'=>$config['origin']!==null,
            'destination_revision'=>$config['revision'],'publications'=>$rows];
    }
    public function configure(Principal $actor,\stdClass $body): array
    {
        $this->policy->require($actor,'access.manage');
        if (array_diff(array_keys(get_object_vars($body)),['webhook_url','clear_webhook','allow_media']) || !is_bool($body->allow_media ?? null)
            || isset($body->webhook_url) && !is_string($body->webhook_url) || isset($body->clear_webhook) && !is_bool($body->clear_webhook)) { throw new \InvalidArgumentException('Gemeinschaftsverbindung und Medienfreigabe prüfen.'); }
        $this->settings->change(function(\stdClass $settings) use ($body,$actor) {
            $this->policy->require($actor,'access.manage'); $previous=$settings->community_discord ?? new \stdClass();
            $url=trim($body->webhook_url ?? '') ?: ($previous->webhook_url ?? ''); if ($body->clear_webhook ?? false) $url='';
            if (!is_string($url) || $url!=='' && !DiscordWebhook::valid($url)) { throw new \InvalidArgumentException('Gültigen eigenen Gemeinschafts-Webhook verwenden.'); }
            $settings->community_discord=(object)['webhook_url'=>$url,'allow_media'=>$body->allow_media];
        });
        return ['message'=>'Gemeinschaftsverbindung gespeichert. Veröffentlichungen werden einzeln freigegeben.'];
    }
    public static function validate(?\stdClass $body): void
    {
        if (!$body || array_diff(array_keys(get_object_vars($body)),['kind','event_id','revision','media','destination_revision','replace','generation'])
            || !in_array($body->kind ?? null,['event','summary'],true) || !GameCatalog::identifier($body->event_id ?? null)
            || !is_int($body->revision ?? null) || $body->revision<0 || !is_bool($body->media ?? null) || !is_bool($body->replace ?? null)
            || !is_string($body->destination_revision ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$body->destination_revision)
            || !is_string($body->generation ?? null) || !preg_match('/^(?:new|[a-f0-9]{64})$/D',$body->generation)
            || $body->kind==='summary' && ($body->event_id!=='community-summary' || $body->media)) { throw new \InvalidArgumentException('Ungültige Veröffentlichungsfreigabe.'); }
    }
    public function generation(string $id): string
    {
        $row=$this->db->fetchAssociative('SELECT * FROM community_discord_messages WHERE event_id=?',[$id]);
        return $row ? hash('sha256',json_encode([$row['remote_id'],$row['revision'],$row['state'],$row['webhook_hash']],JSON_THROW_ON_ERROR)) : 'new';
    }
    public function execute(Principal $actor,\stdClass $body,callable $guard): array
    {
        self::validate($body); $guard(); $this->policy->require($actor,'community.export'); $config=$this->configuration();
        if ($config['url']==='' || $config['origin']===null || !hash_equals($config['revision'],$body->destination_revision)) { throw new \DomainException('Ziel oder Freigaben wurden geändert. Veröffentlichung bitte neu beauftragen.'); }
        if ($body->media && !$config['media']) { throw new \DomainException('Medienübertragung ist nicht freigegeben.'); }
        $id=$body->event_id; $revision=$body->revision; $jpeg=null;
        if ($body->kind==='summary') {
            $page=$this->reader->page($actor,['limit'=>'5']); $revision=time();
            $content=implode("\n",array_map(fn($event)=>'✦ '.$event['title'].' · '.($event['badge_label'] ?? $event['badge_id']),$page['events']));
            $embed=['title'=>'Die Sturmklingen · Unsere Gemeinschaft','description'=>$content ?: 'Unsere gemeinsame Geschichte beginnt hier.',
                'url'=>$page['events'] ? $config['origin'].'/#scene-'.$page['events'][0]['id'] : $config['origin'].'/','color'=>14657920,'footer'=>['text'=>'Letzte fünf Chronikmomente · Stand '.gmdate('d.m.Y H:i').' UTC']];
        } else {
            $event=$this->reader->detail($actor,$id)['event'];
            if ($event['revision']!==$revision) { throw new \DomainException('Der Moment wurde geändert. Aktuelle Version erneut freigeben.'); }
            $labels=array_column($this->games->entries($this->settings->read()->catalog_games ?? []),'label','id');
            $fields=[['name'=>'Spiel / Kampagne','value'=>($labels[$event['game_id']] ?? $event['game_id']).' · '.(($event['campaign_label'] ?? '') ?: 'Ohne Kampagne')]];
            foreach (array_chunk($event['member_ids'],8) as $index=>$members) { $fields[]=['name'=>$index===0 ? 'Beteiligte' : 'Weitere Beteiligte','value'=>implode(', ',array_map(fn($member)=>$event['member_names']->{$member} ?? $member,$members))]; }
            $fields[]=['name'=>$event['occurred_at'] ? 'Erlebt' : 'Erfasst','value'=>$event['occurred_at'] ?: $event['recorded_at']];
            $embed=['title'=>($event['removed'] ?? false) ? $event['title'].' · zurückgezogen' : $event['title'],
                'description'=>($event['removed'] ?? false) ? 'Dieser Gemeinschaftsmoment wurde mit Begründung zurückgezogen.' : ($event['description'] ?? ''),
                'url'=>$config['origin'].'/#scene-'.$id,'color'=>14657920,'fields'=>$fields,
                'footer'=>['text'=>($event['badge_label'] ?? $event['badge_id']).' · '.(['manual'=>'Manuell bestätigt','steam-import'=>'Historischer Steam-Import','verified-game'=>'Spielnachweis'][$event['source']] ?? 'Gemeinschaftsmoment')]];
            if ($body->media && isset($event['media_id']) && !($event['removed'] ?? false)) { $jpeg=$this->media->get($actor,$id,(string)$revision); $embed['image']=['url'=>'attachment://preview.jpg']; }
        }
        $payload=['content'=>'','embeds'=>[$embed],'attachments'=>$jpeg===null ? [] : [['id'=>0,'filename'=>'preview.jpg']]];
        $hash=hash('sha256',$config['url']); $old=$this->db->fetchAssociative('SELECT * FROM community_discord_messages WHERE event_id=?',[$id]);
        if (!$body->replace && $old && $old['webhook_hash']===$hash && (int)$old['revision']===$revision && (bool)$old['media']===($jpeg!==null) && $old['state']==='sent') { return ['message'=>'Diese Ereignisversion ist bereits veröffentlicht.']; }
        if ($body->generation!==$this->generation($id)) { throw new \DomainException('Veröffentlichungsstand wurde geändert. Bitte neu laden.'); }
        if (!$body->replace && $old && ($old['webhook_hash']!==$hash || !$old['remote_id'] && $old['state']!=='limited' || $old['state']==='blocked')) { throw new \DomainException('Vorherige Veröffentlichung unklar, entfernt oder anderer Kanal. Erst prüfen und ausdrücklich neue Nachricht wählen.'); }
        if ($old && (int)$old['next_try']>time()) { throw new \DomainException('Discord-Wartezeit läuft noch. Später erneut freigeben.'); }
        $remote=$body->replace ? null : ($old['remote_id'] ?? null);
        $row=['webhook_hash'=>$hash,'remote_id'=>$remote,'revision'=>$revision,'state'=>'sending','media'=>(int)($jpeg!==null),'template_version'=>1,
            'requested_by'=>$actor->id,'attempted_at'=>time(),'next_try'=>0,'error'=>null];
        if ($old) { $this->db->update('community_discord_messages',$row,['event_id'=>$id]); } else { $this->db->insert('community_discord_messages',['event_id'=>$id,...$row]); }
        try {
            $guard(); $this->policy->require($actor,'community.export');
            if ($this->configuration()['revision']!==$body->destination_revision) { throw new \DomainException('Freigabe geändert.'); }
            $confirmed=$this->transport->card($config['url'],$payload,$jpeg,$remote);
            if (!preg_match('/^[0-9]{1,30}$/D',$confirmed) || $remote!==null && $confirmed!==$remote) { throw new OutcomeUnknown('Nachrichtenkennung unklar.'); }
            $this->db->update('community_discord_messages',['remote_id'=>$confirmed,'state'=>'sent'],['event_id'=>$id]);
        } catch (\Throwable $error) {
            $this->db->update('community_discord_messages',['state'=>$error instanceof DiscordRateLimit ? 'limited' : ($error instanceof \DomainException ? 'blocked' : 'uncertain'),
                'next_try'=>$error instanceof DiscordRateLimit ? time()+$error->seconds : 0,'error'=>'Discord hat die Veröffentlichung nicht bestätigt. Vor erneutem Anlegen im Kanal prüfen.'],['event_id'=>$id]);
            throw new \DomainException('Gemeinschaftsveröffentlichung nicht bestätigt. Status im Dashboard prüfen.');
        }
        return ['message'=>'Gemeinschaftskarte veröffentlicht.','remote_id'=>$confirmed,'event_id'=>$id];
    }
}
