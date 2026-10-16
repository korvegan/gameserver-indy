<?php
declare(strict_types=1);

namespace App\Http;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Access\ListenerIdentity;
use App\Job\JobRepository;
use App\Job\JobReconciler;
use App\Job\JobService;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SliceController extends AbstractController
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs,
        private readonly JobService $service, private readonly ServerControl $control,
        private readonly JobReconciler $reconciler, private readonly AccessPolicy $policy,
        private readonly SessionKeys $keys, private readonly ListenerIdentity $identity) {}

    private function principal(): Principal
    {
        $principal = $this->getUser();
        if (!$principal instanceof Principal) { throw new AccessDeniedException('Persönliche Identität fehlt.'); }
        return $principal;
    }
    private function servers(Principal $principal): array
    {
        return array_filter(SliceRuntime::SERVERS, fn ($id) => $principal->allows('status.read', $id), ARRAY_FILTER_USE_KEY);
    }

    #[Route('/', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $this->denyAccessUnlessGranted('catalog.read'); $principal = $this->principal();
        return $this->render('slice.html.twig', ['token' => $this->keys->token($principal, $request->attributes->get('_indy_scope')),
            'role' => $principal->role, 'permissions' => $principal->permissions(),
            'driver' => $this->runtime->driver, 'servers' => $this->servers($principal)]);
    }

    #[Route('/api/session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('catalog.read'); $principal = $this->principal();
        return $this->json(['token' => $this->keys->token($principal, $request->attributes->get('_indy_scope')),
            'role' => $principal->role, 'principal' => $principal->public(), 'grant_revision' => $principal->revision, 'api_version' => 1,
            'access_mode' => $request->attributes->get('_indy_scope') === 'local-owner' ? 'local' : 'private',
            'isolated' => true, 'driver' => $this->runtime->driver, 'servers' => $this->servers($principal)]);
    }

    #[Route('/api/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $serverId = (string) $request->query->get('server', 'demo-a');
        $this->denyAccessUnlessGranted('status.read', $serverId);
        return $this->json(['server_id' => $serverId, 'status' => $this->control->status($serverId)]);
    }

    #[Route('/api/jobs', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $this->reconciler->reconcile();
        $id = $request->query->get('id');
        $principal = $this->principal(); $this->denyAccessUnlessGranted('jobs.read');
        if ($id !== null) {
            $job = $this->jobs->get($id); $this->denyAccessUnlessGranted('jobs.read', $job['server_id']);
            return $this->json(['job' => JobRepository::public($job)]);
        }
        $snapshot = $this->jobs->snapshot();
        $snapshot['jobs'] = array_values(array_map(JobRepository::public(...),
            array_filter($snapshot['jobs'], fn ($job) => $this->policy->visible($principal, $job))));
        return $this->json($snapshot);
    }

    #[Route('/api/jobs', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('server.start');
        $body = $request->toArray();
        if (array_diff(array_keys($body), ['server_id', 'operation', 'request_id'])) {
            throw new \InvalidArgumentException('Unbekannte Felder. Rollen und Prozesspfade werden nicht angenommen.');
        }
        foreach (['server_id', 'operation', 'request_id'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                throw new \InvalidArgumentException('Befehl unvollständig.');
            }
        }
        return $this->json(['job' => JobRepository::public($this->service->submit($body['server_id'], $body['operation'], $body['request_id'], $this->principal()))], 202);
    }

    #[Route('/api/job-events', methods: ['GET'])]
    public function events(Request $request): EventStreamResponse
    {
        $this->denyAccessUnlessGranted('jobs.read'); $principal = $this->principal();
        $cursor = $request->headers->get('Last-Event-ID', (string) $request->query->get('after', '0'));
        if (!preg_match('/^[0-9]{1,18}$/D', $cursor)) {
            throw new \InvalidArgumentException('Ungültiger Ereignisstand.');
        }
        $after = (int) $cursor;
        $scope = $request->attributes->get('_indy_scope');
        return new EventStreamResponse(function () use ($after, $principal, $request, $scope): iterable {
            $deadline = microtime(true) + 10;
            $heartbeat = 0.0;
            $reconcileAt = 0.0;
            while (!connection_aborted() && microtime(true) < $deadline) {
                try {
                    [$current, $currentScope] = $this->identity->resolve($request);
                    $this->policy->require($principal, 'jobs.read');
                    if ($current->claim() !== $principal->claim() || $currentScope !== $scope) { throw new AccessDeniedException(); }
                }
                catch (AccessDeniedException) {
                    yield new ServerEvent('Freigabe geändert. Bitte neu laden.', type: 'access-revoked'); return;
                }
                if (microtime(true) >= $reconcileAt) {
                    $this->reconciler->reconcile();
                    $reconcileAt = microtime(true) + 1;
                }
                foreach ($this->jobs->events($after) as $event) {
                    $after = (int) $event['sequence'];
                    $job = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
                    if ($this->policy->visible($principal, $job)) {
                        yield new ServerEvent(json_encode(JobRepository::public($job), JSON_THROW_ON_ERROR), type: 'job', id: (string) $after);
                    }
                }
                if (microtime(true) > $heartbeat) {
                    yield new ServerEvent('', comment: 'heartbeat');
                    $heartbeat = microtime(true) + 2;
                }
                usleep(100000);
            }
        });
    }
}
