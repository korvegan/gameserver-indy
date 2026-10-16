<?php
declare(strict_types=1);

namespace App\Http;

use App\Runtime\SliceRuntime;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class LocalBoundary
{
    public function __construct(private readonly SliceRuntime $runtime) {}

    #[AsEventListener(event: 'kernel.request', priority: 256)]
    public function authorize(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        $host = $request->headers->get('Host', '');
        $port = (string) $request->server->get('SERVER_PORT', '');
        if ($request->server->get('REMOTE_ADDR') !== '127.0.0.1'
            || !in_array($host, ['127.0.0.1:'.$port, 'localhost:'.$port], true)) {
            $event->setResponse(new JsonResponse(['error' => 'Nur lokaler isolierter Zugriff erlaubt.'], 403));
            return;
        }
        if ($request->isMethod('POST')) {
            if ($request->headers->get('Origin') !== 'http://'.$host) {
                $event->setResponse(new JsonResponse(['error' => 'Unzulässiger Ursprung.'], 403));
            } elseif ($this->runtime->role !== 'owner') {
                $event->setResponse(new JsonResponse(['error' => 'Die Leserrolle darf keine Serverbefehle beauftragen.'], 403));
            } elseif (!hash_equals($this->runtime->csrfToken(), $request->headers->get('X-Manager-Token', ''))) {
                $event->setResponse(new JsonResponse(['error' => 'Sitzung aktualisieren. Dieser Befehl wurde nicht ausgeführt.', 'code' => 'session_expired'], 403));
            } elseif ($request->getContentTypeFormat() !== 'json') {
                $event->setResponse(new JsonResponse(['error' => 'JSON erforderlich.'], 415));
            } elseif (strlen($request->getContent()) > 4096) {
                $event->setResponse(new JsonResponse(['error' => 'Anfrage zu groß.'], 413));
            }
        }
    }

    #[AsEventListener(event: 'kernel.exception', priority: 128)]
    public function error(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode()
            : ($error instanceof \InvalidArgumentException || $error instanceof \JsonException ? 400 : ($error instanceof \DomainException ? 409 : 500));
        $event->setResponse(new JsonResponse(['error' => $status >= 500 ? 'Isolierte Verwaltung nicht bereit. Lokales Testprotokoll prüfen.' : $error->getMessage()], $status));
    }

    #[AsEventListener(event: 'kernel.response')]
    public function headers(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'");
    }
}
