<?php
declare(strict_types=1);
namespace App\Integration;

use App\Runtime\OutcomeUnknown;
use App\Storage\UnicodeText;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** No retries or redirects: an uncertain POST may already have been delivered. */
final class DiscordWebhook implements DiscordTransport
{
    public function __construct(private readonly HttpClientInterface $client) {}
    public static function valid(string $url): bool { return preg_match('~^https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v[0-9]+)?/webhooks/[0-9]+/[A-Za-z0-9_-]+$~D', $url) === 1; }
    public static function message(mixed $message): string
    {
        if (!is_string($message) || !mb_check_encoding($message, 'UTF-8')) { throw new \InvalidArgumentException('Ungültige Discord-Nachricht.'); }
        $message = UnicodeText::trim($message);
        if (mb_strlen($message) < 1 || mb_strlen($message) > 2000) { throw new \InvalidArgumentException('Die Discord-Nachricht muss 1 bis 2000 Zeichen enthalten.'); }
        return $message;
    }
    public function send(string $url, string $message): string
    {
        if (!self::valid($url)) { throw new \InvalidArgumentException('Bitte eine gültige Discord-Webhook-URL verwenden.'); }
        $message = self::message($message); $response = null;
        try {
            $response = $this->client->request('POST', $url.'?wait=true', ['json' => ['content' => $message, 'allowed_mentions' => ['parse' => []]],
                'headers' => ['User-Agent' => 'GameServerIndy/0.3'], 'max_redirects' => 0, 'timeout' => 12, 'max_duration' => 12, 'buffer' => false]);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 500) { throw new \DomainException($status === 429 ? 'Discord begrenzt die Anfragen. Später einen neuen Versand beauftragen.' : 'Discord hat die Nachricht abgelehnt (HTTP '.$status.').'); }
            if ($status < 200 || $status >= 300) { throw new OutcomeUnknown('Discord-Versand unklar. Kein automatischer Wiederholungsversuch.'); }
            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > 16384) { throw new OutcomeUnknown('Discord-Antwort zu groß; Versand unklar.'); }
            }
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['id'] ?? null) || !preg_match('/^[0-9]{1,30}$/D', $data['id'])) { throw new OutcomeUnknown('Discord hat keine Nachrichtenkennung bestätigt. Versand unklar.'); }
            return $data['id'];
        } catch (\DomainException|OutcomeUnknown $error) { throw $error; }
        catch (\Throwable) { throw new OutcomeUnknown('Discord-Versand unterbrochen. Ausgang unbekannt; kein automatischer Wiederholungsversuch.'); }
        finally { $response?->cancel(); }
    }
}
