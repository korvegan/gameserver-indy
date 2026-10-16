<?php
declare(strict_types=1);

namespace App\Http;

use App\Access\AccessStore;
use App\Access\ListenerIdentity;
use App\Access\SessionKeys;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class LocalBoundary
{
    public function __construct(private readonly ListenerIdentity $identity, private readonly SessionKeys $keys,
        private readonly AccessStore $access, private readonly LoggerInterface $logger) {}

    #[AsEventListener(event: 'kernel.request', priority: 256)]
    public function authorize(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        [$principal, $scope, $origin] = $this->identity->resolve($request);
        $request->attributes->set('_indy_principal', $principal);
        $request->attributes->set('_indy_scope', $scope);
        if ($request->isMethod('POST')) {
            if ($request->headers->get('Origin') !== $origin) {
                $event->setResponse(new JsonResponse(['error' => 'Unzulässiger Ursprung.'], 403));
            } elseif (!hash_equals($this->keys->token($principal, $scope), $request->headers->get('X-Manager-Token', ''))) {
                $event->setResponse(new JsonResponse(['error' => 'Sitzung aktualisieren. Dieser Befehl wurde nicht ausgeführt.', 'code' => 'session_expired'], 403));
            } elseif ($request->getContentTypeFormat() !== 'json') {
                $event->setResponse(new JsonResponse(['error' => 'JSON erforderlich.'], 415));
            } elseif (strlen($request->getContent()) > (in_array($request->getPathInfo(), ['/api/config', '/api/community'], true) ? 1048576 : 4096)) {
                $event->setResponse(new JsonResponse(['error' => 'Anfrage zu groß.'], 413));
            }
        }
    }

    #[AsEventListener(event: 'kernel.exception', priority: 128)]
    public function error(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $status = match (true) {
            $error instanceof AccessDeniedException => 403,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            $error instanceof \InvalidArgumentException, $error instanceof \JsonException => 400,
            $error instanceof \DomainException => 409, default => 500,
        };
        if ($status >= 500) {
            $this->logger->error('Manager failure at {source}:{line} ({class})',
                ['source' => basename($error->getFile()), 'line' => $error->getLine(), 'class' => $error::class]);
        }
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
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api/') && ($request->isMethod('POST')
                || $event->getResponse()->getStatusCode() >= 400 || $request->getPathInfo() === '/api/session')) {
            $operation = in_array($request->getPathInfo(), ['/api/session', '/api/status', '/api/jobs', '/api/job-events',
                '/api/access', '/api/access/users', '/api/access/settings', '/api/config', '/api/setup',
                '/api/servers', '/api/connections/settings', '/api/community', '/api/community/options', '/api/community/event',
                '/api/community/story', '/api/community/media', '/api/community/export'], true) ? $request->getPathInfo() : 'request.unknown';
            $this->access->audit($request->attributes->get('_indy_principal')?->id ?? 'unrecognized',
                $operation, '', (string) $event->getResponse()->getStatusCode());
        }
    }
}
