<?php
declare(strict_types=1);
namespace App\Access;

use App\Runtime\SliceRuntime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Direct listener/peer and exact Host are authoritative, never Forwarded headers. */
final class ListenerIdentity
{
    public function __construct(private readonly AccessStore $store, private readonly SliceRuntime $runtime,
        private readonly int $localPort) {}
    public function resolve(Request $request): array
    {
        // FrankenPHP derives SERVER_PORT from the public Host. The php_server
        // block sets this protected CGI variable from its configured listener.
        $port = (int) $request->server->get('INDY_LISTENER_PORT'); $host = $request->headers->get('Host', '');
        if ($request->server->get('REMOTE_ADDR') !== '127.0.0.1' || count($request->headers->all('Host')) !== 1) {
            throw new AccessDeniedException('Zugriff nur über den vorgesehenen lokalen Listener.');
        }
        if ($port === $this->localPort && in_array($host, ['127.0.0.1:'.$port, 'localhost:'.$port], true)) {
            return [new Principal('local-owner', $this->runtime->role), 'local-owner', 'http://'.$host];
        }
        $settings = $this->store->settings();
        if (!$settings['enabled'] || $port !== $settings['port'] || $port === $this->localPort
            || !in_array($host, [$settings['hostname'], $settings['hostname'].':443'], true)
            || count($request->headers->all('Tailscale-User-Login')) !== 1) {
            throw new AccessDeniedException('Privater Zugriff nur über den bestätigten Tailscale-HTTPS-Listener.');
        }
        return [$this->store->principal($request->headers->get('Tailscale-User-Login')),
            'family:'.$settings['hostname'], 'https://'.$host];
    }
}
