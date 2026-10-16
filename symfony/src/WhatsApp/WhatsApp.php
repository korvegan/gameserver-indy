<?php
declare(strict_types=1);
namespace App\WhatsApp;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Integration\Connections;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\Readiness;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class WhatsApp
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games, private readonly Connections $connections, private readonly Readiness $readiness,
        private readonly ProcessObservation $observations, private readonly ProcessGateway $gateway, private readonly RuntimeLifecycle $lifecycle,
        private readonly WhatsAppClient $client, private readonly DeliveryStore $deliveries, private readonly AccessPolicy $policy) {}
    public function owner(Principal $actor): void
    {
        $this->policy->require($actor,'discord.write');
        if (!$actor->localOwner()) { throw new AccessDeniedException('Das persönliche WhatsApp-Konto wird ausschließlich am lokalen Besitzerzugang verwaltet.'); }
    }
    private function fence(Principal $actor): callable
    {
        $this->owner($actor); $epoch = $this->lifecycle->epoch();
        return function () use ($actor,$epoch): void { $this->owner($actor); $this->lifecycle->requireClaim(['runtime_epoch' => $epoch]); };
    }
    private function locked(callable $operation): mixed
    {
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('whatsapp-settings'); $lock->acquire(true);
        try { return $operation(); } finally { $lock->release(); }
    }
    private function path(): string { return $this->runtime->directory.'/data/whatsapp/settings.json'; }
    public static function validate(\stdClass $settings): \stdClass
    {
        if (!is_string($settings->group_id ?? null) || $settings->group_id !== '' && !LocalWhatsAppClient::group($settings->group_id)
            || !is_string($settings->group_label ?? null) || !is_bool($settings->enabled ?? null) || !is_int($settings->revision ?? null) || $settings->revision < 0
            || !is_array($settings->server_ids ?? null) || !array_is_list($settings->server_ids) || array_filter($settings->server_ids, fn ($id) => !is_string($id))
            || count(array_unique($settings->server_ids)) !== count($settings->server_ids)) { throw new \DomainException('WhatsApp-Einstellungen sind nicht kompatibel.'); }
        return $settings;
    }
    public function settings(): \stdClass
    {
        $this->runtime->assertInitialized();
        return is_file($this->path()) ? self::validate(PrivateFiles::object(PrivateFiles::read($this->path(),65536)))
            : (object) ['group_id'=>'','group_label'=>'','enabled'=>false,'server_ids'=>[],'revision'=>0];
    }
    private function write(\stdClass $settings): void { self::validate($settings); PrivateFiles::directory(dirname($this->path())); PrivateFiles::write($this->path(),PrivateFiles::json($settings)); }
    public function snapshot(string $id, bool $fresh = false): array
    {
        $profile = $this->registry->get($id); $status = $fresh ? $this->gateway->call($id,'Status') : $this->observations->view($id);
        $evidence = $this->readiness->probe($id,$status); $error = null; $text = '';
        try {
            $details = $this->connections->details($id);
            if ($details['mode'] === 'ip_query' && !$details['public_address']) { throw new \DomainException('Öffentliche Beitrittsadresse fehlt.'); }
            $text = $this->games->schema($profile->game)->label.' · '.$details['server_name']."\n".($details['public_address'] ?? 'Im Spiel in der Serverliste suchen')."\n"
                .($evidence['state'] === 'ready' ? '✅ Bereit zum Beitritt' : '⏳ Bereitschaft noch nicht bestätigt');
        } catch (\Throwable) { $error = 'Beitrittsadresse oder Spielkonfiguration zuerst prüfen.'; }
        return ['server_id'=>$id,'label'=>$profile->label,'readiness'=>$evidence,'message'=>$text,'error'=>$error];
    }
    public function view(Principal $actor): array
    {
        $this->owner($actor); $settings = $this->settings(); $state = 'NOT_INSTALLED'; $error = null;
        if ($this->client->configured()) { try { $state = $this->client->session()['status']; } catch (\Throwable) { $state = 'UNAVAILABLE'; $error = 'Der lokale WhatsApp-Dienst ist nicht erreichbar.'; } }
        $previews = [];
        foreach ($this->registry->settings()->servers as $profile) {
            try { $preview = $this->snapshot($profile->id); $key = $preview['readiness']['process_key'] ?? ''; $preview['delivery'] = $this->deliveries->state($profile->id,$key,$settings->group_id); }
            catch (\Throwable) { $preview = ['server_id'=>$profile->id,'label'=>$profile->label,'readiness'=>['state'=>'unknown','label'=>'Nicht ermittelbar'],'message'=>'','error'=>'Serverstatus derzeit nicht ermittelbar.','delivery'=>null]; }
            $previews[] = $preview;
        }
        return ['state'=>$state,'error'=>$error,'settings'=>$settings,'previews'=>$previews];
    }
    public function configure(Principal $actor, \stdClass $body): array
    {
        $guard = $this->fence($actor);
        if (array_diff(array_keys(get_object_vars($body)), ['group_id','enabled','server_ids','revision']) || count(get_object_vars($body)) !== 4
            || !is_string($body->group_id ?? null) || !is_bool($body->enabled ?? null) || !is_int($body->revision ?? null)
            || !is_array($body->server_ids ?? null) || !array_is_list($body->server_ids) || array_filter($body->server_ids,fn($id)=>!is_string($id))
            || count(array_unique($body->server_ids)) !== count($body->server_ids)) { throw new \InvalidArgumentException('Bitte Zielgruppe und Serverprofile ausdrücklich auswählen.'); }
        $known = array_map(fn($profile)=>$profile->id,$this->registry->settings()->servers);
        if (array_diff($body->server_ids,$known) || $body->enabled && !$body->server_ids) { throw new \InvalidArgumentException('Bitte vorhandene Serverprofile für automatische Meldungen auswählen.'); }
        return $this->locked(function () use ($body,$guard): array {
            $guard(); $previous = $this->settings();
            if ($body->revision !== $previous->revision) { throw new \DomainException('Die WhatsApp-Einstellungen wurden inzwischen geändert. Bitte neu laden.'); }
            $label = $previous->group_label;
            if ($body->enabled || $body->group_id !== $previous->group_id) {
                $groups = array_column($this->client->groups(),'label','id');
                if (!isset($groups[$body->group_id])) { throw new \DomainException('Bitte eine aktuell verfügbare WhatsApp-Gruppe auswählen.'); } $label = $groups[$body->group_id];
            }
            $guard(); $updated = clone $body; $updated->group_label = $label; ++$updated->revision; $this->write($updated);
            return ['message'=>'WhatsApp-Einstellungen gespeichert.','revision'=>$updated->revision];
        });
    }
    public function connection(Principal $actor, bool $connect): array
    {
        $guard = $this->fence($actor);
        return $this->locked(function () use ($connect,$guard): array {
            $guard(); $previous = $this->settings(); $this->write((object) ['group_id'=>'','group_label'=>'','enabled'=>false,'server_ids'=>[],'revision'=>$previous->revision+1]);
            $guard(); if ($connect) { $this->client->connect(); } else { $this->client->logout(); }
            return ['message'=>$connect ? 'Geräteverknüpfung vorbereitet. QR-Code mit WhatsApp auf dem Handy scannen.' : 'WhatsApp-Verknüpfung getrennt; automatische Meldungen deaktiviert.'];
        });
    }
    public function send(Principal $actor, string $id, int $revision, bool $automatic = false): array
    {
        $guard = $this->fence($actor); $snapshot = $this->snapshot($id,true); $evidence = $snapshot['readiness'];
        if ($evidence['state'] !== 'ready' || $snapshot['message'] === '') { throw new \DomainException('Ein Beitritt ist noch nicht lokal bestätigt. Es wurde nichts gesendet.'); }
        return $this->locked(function () use ($id,$revision,$automatic,$guard,$snapshot,$evidence): array {
            $guard(); $settings = $this->settings();
            if ($settings->group_id === '' || !in_array($id,$settings->server_ids,true) || $settings->revision !== $revision || $automatic && !$settings->enabled) { throw new \DomainException('WhatsApp-Ziel oder Freigabe wurde geändert. Es wurde nichts gesendet.'); }
            if ($this->client->session()['status'] !== 'WORKING') { throw new \DomainException('WhatsApp ist derzeit nicht verbunden. Es wurde nichts gesendet.'); }
            $identity = Readiness::identity($this->gateway->call($id,'Status')); $guard();
            if ($identity === null || $identity[0] !== $evidence['process_key']) { throw new \DomainException('Der Serverprozess hat sich geändert. Es wurde nichts gesendet.'); }
            if (!$this->deliveries->claim($id,$identity[0],$settings->group_id)) { return ['message'=>'Für diesen Serverstart wurde die WhatsApp-Meldung bereits gesendet.']; }
            $sent = false;
            try { $guard(); $this->client->send($settings->group_id,$snapshot['message']); $sent = true; }
            finally { $this->deliveries->finish($id,$identity[0],$settings->group_id,$sent); }
            return ['message'=>'Kurze Bereitschaftsmeldung an die ausgewählte WhatsApp-Gruppe gesendet.'];
        });
    }
    /** Only an explicit single-supervisor tick may send automatic notifications. */
    public function tick(): void
    {
        $actor = new Principal('local-owner','owner'); $guard = $this->fence($actor); $settings = $this->settings();
        if (!$settings->enabled || !$this->client->configured()) { return; }
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('whatsapp-poll'); if (!$lock->acquire()) { return; }
        try {
            foreach ($this->registry->settings()->servers as $profile) {
                $guard(); if (!in_array($profile->id,$settings->server_ids,true)) { continue; }
                try { $this->send($actor,$profile->id,$settings->revision,true); } catch (\Throwable) { /* Durable state/preview retain the outcome. */ }
            }
        } finally { $lock->release(); }
    }
    public function groups(Principal $actor): array { $this->owner($actor); return $this->client->groups(); }
    public function qr(Principal $actor): string { $this->owner($actor); return $this->client->qr(); }
}
