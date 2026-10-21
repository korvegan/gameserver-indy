<?php
declare(strict_types=1);
namespace App\Http;

use App\Runtime\RuntimeSignals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class MutationSignals
{
    public function __construct(private readonly RuntimeSignals $signals) {}
    #[AsEventListener]
    public function response(ResponseEvent $event): void
    {
        $request = $event->getRequest(); $path = $request->getPathInfo();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !$event->getResponse()->isSuccessful() || !str_starts_with($path, '/api/')) { return; }
        $topic = str_starts_with($path, '/api/community') ? 'community' : (str_starts_with($path, '/api/access') ? 'access' : 'settings');
        if ($path === '/api/jobs') { return; } // JobRepository wakes only after commit.
        $this->signals->publish($topic);
    }
}
