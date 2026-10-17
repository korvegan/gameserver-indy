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
    { return $this->request('POST', $url, ['content'=>self::message($message)]); }
    public function edit(string $url, string $messageId, string $message): string
    {
        if (!preg_match('/^[0-9]{1,30}$/D', $messageId)) { throw new \InvalidArgumentException('Ungültige Nachrichtenkennung.'); }
        return $this->request('PATCH', $url, ['content'=>self::message($message)], $messageId);
    }
    public function card(string $url,array $payload,?string $jpeg=null,?string $messageId=null): string
    {
        if ($messageId!==null && !preg_match('/^[0-9]{1,30}$/D',$messageId)) { throw new \InvalidArgumentException('Ungültige Nachrichtenkennung.'); }
        if ($jpeg!==null) { \App\Community\ChronicleValues::jpeg($jpeg); }
        return $this->request($messageId===null ? 'POST' : 'PATCH',$url,$payload,$messageId,$jpeg);
    }
    private function request(string $method, string $url, array $payload, ?string $messageId = null,?string $jpeg=null): string
    {
        if (!self::valid($url)) { throw new \InvalidArgumentException('Bitte eine gültige Discord-Webhook-URL verwenden.'); }
        $payload['allowed_mentions']=['parse'=>[]]; $response = null;
        try {
            $options=['json'=>$payload,'headers'=>['User-Agent'=>'GameServerIndy/0.3'],'max_redirects'=>0,'timeout'=>12,'max_duration'=>12,'buffer'=>false];
            if ($jpeg!==null) {
                $boundary='indy-'.bin2hex(random_bytes(16));
                $options['body']='--'.$boundary."\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n".json_encode($payload,JSON_THROW_ON_ERROR)
                    ."\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"preview.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".$jpeg."\r\n--".$boundary."--\r\n";
                unset($options['json']); $options['headers']['Content-Type']='multipart/form-data; boundary='.$boundary;
            }
            $response = $this->client->request($method, $url.($messageId === null ? '?wait=true' : '/messages/'.$messageId),$options);
            $status = $response->getStatusCode();
            if ($status === 429) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? 60;
                throw new DiscordRateLimit(is_numeric($retry) ? max(1,min(86400,(int)ceil((float)$retry))) : 60);
            }
            if ($status >= 300 && $status < 500) { throw new \DomainException('Discord hat die Nachricht abgelehnt (HTTP '.$status.').'); }
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
