<?php
declare(strict_types=1);
namespace App\Integration;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Runtime\OutcomeUnknown;
use Doctrine\DBAL\Connection;

final class Discord
{
    public function __construct(private readonly ManagerSettings $settings, private readonly AccessPolicy $policy,
        private readonly DiscordTransport $transport, private readonly Connection $db) {}
    public function initialize(): void
    {
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS discord_deliveries (id TEXT PRIMARY KEY, revision TEXT NOT NULL,
            state TEXT NOT NULL, remote_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS discord_status_cards (server_id TEXT PRIMARY KEY, webhook_hash TEXT NOT NULL,
            remote_id TEXT, revision TEXT NOT NULL, state TEXT NOT NULL, enabled INTEGER NOT NULL, attempted_at INTEGER NOT NULL,
            updated_at INTEGER, next_try INTEGER NOT NULL, error TEXT, generation TEXT NOT NULL)');
        $this->db->executeStatement('CREATE TABLE IF NOT EXISTS community_discord_messages (event_id TEXT PRIMARY KEY, webhook_hash TEXT NOT NULL,
            remote_id TEXT, revision INTEGER NOT NULL, state TEXT NOT NULL, media INTEGER NOT NULL, template_version INTEGER NOT NULL,
            requested_by TEXT NOT NULL, attempted_at INTEGER NOT NULL, next_try INTEGER NOT NULL, error TEXT)');
    }
    private function configuration(): array
    {
        $discord = $this->settings->read()->discord ?? new \stdClass();
        if (!$discord instanceof \stdClass) { throw new \DomainException('Discord-Einstellungen nicht lesbar.'); }
        $environment = getenv('DISCORD_WEBHOOK_URL'); $url = $environment !== false && $environment !== '' ? $environment : ($discord->webhook_url ?? '');
        if (!is_string($url) || $url !== '' && !DiscordWebhook::valid($url)) { throw new \DomainException('Bitte einen gültigen Discord-Webhook hinterlegen.'); }
        return ['url' => $url, 'automatic' => ($discord->auto_notify ?? false) === true, 'environment' => $environment !== false && $environment !== ''];
    }
    public function view(Principal $actor, string $id): array
    {
        $this->policy->require($actor, 'discord.write', $id); $config = $this->configuration();
        return ['configured' => $config['url'] !== '', 'auto_notify' => $config['automatic'], 'environment_override' => $config['environment']];
    }
    public function automatic(): bool { $config = $this->configuration(); return $config['automatic'] && $config['url'] !== ''; }
    /** Internal transport input; never included in HTTP responses or log context. */
    public function webhook(): string { return $this->configuration()['url']; }
    public function save(Principal $actor, string $id, \stdClass $value): array
    {
        $this->policy->require($actor, 'discord.write', $id);
        if (!is_bool($value->auto_notify ?? null) || array_diff(array_keys(get_object_vars($value)), ['auto_notify', 'webhook_url', 'clear_webhook'])
            || isset($value->clear_webhook) && !is_bool($value->clear_webhook) || isset($value->webhook_url) && !is_string($value->webhook_url)) { throw new \InvalidArgumentException('Ungültige Discord-Einstellungen.'); }
        return $this->settings->change(function (\stdClass $settings) use ($actor, $id, $value): array {
            $this->policy->require($actor, 'discord.write', $id); $previous = $settings->discord ?? new \stdClass();
            if (!$previous instanceof \stdClass) { throw new \DomainException('Discord-Einstellungen nicht lesbar.'); }
            $url = trim($value->webhook_url ?? '') ?: ($previous->webhook_url ?? '');
            if (($value->clear_webhook ?? false) === true) { $url = ''; }
            if (!is_string($url) || $url !== '' && !DiscordWebhook::valid($url)) { throw new \InvalidArgumentException('Bitte eine gültige Discord-Webhook-URL verwenden.'); }
            $previous->webhook_url = $url; $previous->auto_notify = $value->auto_notify; $settings->discord = $previous;
            return ['message' => 'Discord-Einstellungen gespeichert.'];
        });
    }
    public function send(string $deliveryId, string $message, callable $guard): array
    {
        $message = DiscordWebhook::message($message); $config = $this->configuration();
        if ($config['url'] === '') { throw new \DomainException('Bitte zuerst einen Discord-Webhook hinterlegen.'); }
        if (!preg_match('/^[a-f0-9]{32}$/D', $deliveryId)) { throw new \InvalidArgumentException('Ungültige Versandkennung.'); }
        $revision = hash('sha256', $config['url']."\0".$message); $time = gmdate('Y-m-d\TH:i:s\Z');
        $guard();
        $this->db->transactional(function () use ($deliveryId, $revision, $time): void {
            $old = $this->db->fetchAssociative('SELECT * FROM discord_deliveries WHERE id=?', [$deliveryId]);
            if ($old) { throw new OutcomeUnknown('Dieser Versand wurde bereits angenommen. Kein automatischer Wiederholungsversuch.'); }
            $this->db->insert('discord_deliveries', ['id' => $deliveryId, 'revision' => $revision, 'state' => 'sending', 'remote_id' => null, 'created_at' => $time, 'updated_at' => $time]);
        });
        try {
            $guard(); $remoteId = $this->transport->send($config['url'], $message);
            if (!preg_match('/^[0-9]{1,30}$/D', $remoteId)) { throw new OutcomeUnknown('Discord hat keine Nachrichtenkennung bestätigt. Versand unklar.'); }
            $this->db->update('discord_deliveries', ['state' => 'sent', 'remote_id' => $remoteId, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')], ['id' => $deliveryId]);
        } catch (\Throwable $error) {
            // Once sending is durable, even an unexpected adapter failure is ambiguous.
            $this->db->update('discord_deliveries', ['state' => $error instanceof \DomainException ? 'failed' : 'uncertain',
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z')], ['id' => $deliveryId]);
            throw $error instanceof \DomainException ? $error : new OutcomeUnknown('Discord-Versand unklar. Kein automatischer Wiederholungsversuch.');
        }
        return ['message' => 'Nachricht an Discord gesendet.', 'delivery_id' => $deliveryId, 'remote_id' => $remoteId];
    }
    /** Called only once by the exclusive supervisor, never from an HTTP read. */
    public function reconcile(): void { $this->db->executeStatement("UPDATE discord_deliveries SET state='uncertain' WHERE state='sending'"); }
}
