<?php
declare(strict_types=1);
namespace App\WhatsApp;

use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use App\Storage\UnicodeText;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fixed loopback WAHA/GOWS API, never an arbitrary browser-controlled proxy. */
final class LocalWhatsAppClient implements WhatsAppClient
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly HttpClientInterface $client) {}
    public function configured(): bool { return is_file($this->runtime->directory.'/data/whatsapp/credentials.json'); }
    public static function group(string $id): bool { return preg_match('/^[0-9]{5,32}(?:-[0-9]{1,20})?@g\.us$/D', $id) === 1; }
    private function request(string $path, ?array $body = null, string $method = 'GET', bool $image = false): mixed
    {
        $this->runtime->assertInitialized();
        if (!$this->configured()) { throw new \DomainException('Der lokale WhatsApp-Dienst ist noch nicht eingerichtet.'); }
        $credentials = PrivateFiles::object(PrivateFiles::read($this->runtime->directory.'/data/whatsapp/credentials.json', 4096)); $key = $credentials->api_key ?? null;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) { throw new \DomainException('Der lokale WhatsApp-Zugang ist nicht lesbar.'); }
        $response = null;
        try {
            $options = ['headers' => ['X-Api-Key' => $key, 'Accept' => $image ? 'image/png' : 'application/json'],
                'max_redirects' => 0, 'no_proxy' => '*', 'timeout' => 12, 'max_duration' => 12, 'buffer' => false];
            if ($body !== null) { $options['json'] = $body === [] ? new \stdClass() : $body; }
            $response = $this->client->request($method, 'http://127.0.0.1:18768'.$path, $options); $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) { throw new WhatsAppError($status); }
            $bytes = '';
            foreach ($this->client->stream($response) as $chunk) { $bytes .= $chunk->getContent(); if (strlen($bytes) > 2097152) { throw new WhatsAppError(); } }
            if ($image) { return str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? $bytes : throw new WhatsAppError(); }
            return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (WhatsAppError $error) { throw $error; }
        catch (\Throwable) { throw new WhatsAppError(); }
        finally { $response?->cancel(); }
    }
    public function session(): array
    {
        try { $data = $this->request('/api/sessions/default'); }
        catch (WhatsAppError $error) { if ($error->status === 404) { return ['status' => 'STOPPED', 'exists' => false]; } throw $error; }
        $status = is_array($data) ? ($data['status'] ?? null) : null;
        if (!in_array($status, ['STOPPED', 'STARTING', 'SCAN_QR_CODE', 'WORKING', 'FAILED', 'PASSKEY_REQUIRED', 'PASSKEY_CONFIRMATION_REQUIRED'], true)) { throw new WhatsAppError(); }
        return ['status' => $status, 'exists' => true];
    }
    public function connect(): void
    {
        $session = $this->session();
        if (!$session['exists']) {
            $this->request('/api/sessions', ['name' => 'default', 'start' => true, 'config' => ['debug' => false, 'webhooks' => [],
                'client' => ['deviceName' => 'GameServer Indy', 'browserName' => 'Chrome'], 'ignore' => ['status' => true, 'channels' => true, 'broadcast' => true],
                'gows' => ['storage' => ['messages' => false, 'chats' => false, 'contacts' => false, 'labels' => false, 'messageSecrets' => false, 'groups' => true]]]], 'POST');
        } elseif (in_array($session['status'], ['STOPPED', 'FAILED'], true)) { $this->request('/api/sessions/default/'.($session['status'] === 'FAILED' ? 'restart' : 'start'), [], 'POST'); }
    }
    public function qr(): string
    {
        if ($this->session()['status'] !== 'SCAN_QR_CODE') { throw new \DomainException('WhatsApp wartet derzeit nicht auf einen QR-Code. Status aktualisieren.'); }
        return $this->request('/api/default/auth/qr', image: true);
    }
    public function groups(): array
    {
        if ($this->session()['status'] !== 'WORKING') { throw new \DomainException('Bitte WhatsApp zuerst per QR-Code verknüpfen.'); }
        $groups = [];
        for ($offset=0; $offset<1000; $offset+=100) {
            $page = $this->request('/api/default/groups?exclude=participants&limit=100&offset='.$offset);
            if (!is_array($page) || !array_is_list($page)) { throw new WhatsAppError(); }
            foreach ($page as $group) {
                if (!is_array($group)) { throw new WhatsAppError(); }
                $id = $group['id'] ?? $group['JID'] ?? null; if (is_array($id)) { $id = $id['_serialized'] ?? null; }
                $name = $group['subject'] ?? $group['name'] ?? $group['Name'] ?? null;
                if (is_string($id) && self::group($id) && is_string($name)) { $groups[$id] = ['id' => $id, 'label' => mb_substr(UnicodeText::trim(preg_replace('/[\p{Z}\x09-\x0d\x1c-\x1f\x{0085}]+/u', ' ', $name)),0,120)]; }
            }
            if (count($page) < 100) {
                $groups = array_values($groups); usort($groups, fn ($a,$b) => [mb_convert_case($a['label'], MB_CASE_FOLD, 'UTF-8'),$a['id']] <=> [mb_convert_case($b['label'], MB_CASE_FOLD, 'UTF-8'),$b['id']]); return $groups;
            }
        }
        throw new \DomainException('Zu viele WhatsApp-Gruppen für die begrenzte Auswahl.');
    }
    public function send(string $groupId, string $text): void
    {
        if (!self::group($groupId)) { throw new \InvalidArgumentException('Bitte eine WhatsApp-Gruppe auswählen.'); }
        $result = $this->request('/api/sendText', ['session' => 'default', 'chatId' => $groupId, 'text' => $text, 'linkPreview' => false], 'POST');
        if (!is_array($result) || empty($result['id'])) { throw new WhatsAppError(); }
    }
    public function logout(): void { $this->request('/api/sessions/default/logout', [], 'POST'); }
}
