<?php
declare(strict_types=1);

namespace App\Http;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Access\ListenerIdentity;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Job\JobReconciler;
use App\Job\JobService;
use App\Runtime\ServerControl;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
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
        private readonly SessionKeys $keys, private readonly ListenerIdentity $identity,
        private readonly InstanceRegistry $registry, private readonly ProcessObservation $observation,
        private readonly \App\Dashboard\ServerOverview $overview, private readonly \App\Runtime\RuntimeSignals $signals) {}

    private function principal(): Principal
    {
        $principal = $this->getUser();
        if (!$principal instanceof Principal) { throw new AccessDeniedException('Persönliche Identität fehlt.'); }
        return $principal;
    }
    private function servers(Principal $principal): array
    {
        return array_filter($this->registry->executionLabels(), fn ($id) => $principal->allows('status.read', $id), ARRAY_FILTER_USE_KEY);
    }

    #[Route('/', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $this->denyAccessUnlessGranted('catalog.read'); $principal = $this->principal();
        if ($this->runtime->driver === 'managed') { return $this->render('dashboard.html.twig'); }
        return $this->render('slice.html.twig', ['token' => $this->keys->token($principal, $request->attributes->get('_indy_scope')),
            'role' => $principal->role, 'permissions' => $principal->permissions(),
            'driver' => $this->runtime->driver, 'servers' => $this->servers($principal)]);
    }

    #[Route('/api/session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('catalog.read'); $principal = $this->principal();
        return $this->json(['token' => $this->keys->token($principal, $request->attributes->get('_indy_scope')),
            'role' => $principal->role, 'principal' => [...$principal->public(),'permissions'=>$this->policy->permissions($principal)], 'grant_revision' => $principal->revision, 'api_version' => 2,
            'access_mode' => $request->attributes->get('_indy_scope') === 'local-owner' ? 'local' : 'private',
            'isolated' => $this->runtime->driver !== 'managed', 'driver' => $this->runtime->driver, 'servers' => $this->servers($principal)]);
    }

    #[Route('/api/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $serverId = (string) $request->query->get('server', 'demo-a');
        $this->denyAccessUnlessGranted('status.read', $serverId);
        if ($this->runtime->driver === 'managed') { return $this->json($this->overview->view($this->principal(),$serverId)); }
        $status = $this->runtime->driver === 'managed' ? $this->observation->view($serverId) : $this->control->status($serverId);
        unset($status['user_profile']);
        return $this->json(['server_id' => $serverId, 'status' => $status]);
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
        $server = $request->query->get('server');
        if ($server !== null) { $this->denyAccessUnlessGranted('jobs.read',$server); }
        $snapshot = $this->jobs->snapshot($server !== null ? [$server] : $principal->serverIds);
        $snapshot['jobs'] = array_map(JobRepository::public(...), $snapshot['jobs']);
        return $this->json($snapshot);
    }

    #[Route('/api/jobs', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('server.start');
        $body = get_object_vars(PrivateFiles::object($request->getContent()));
        if (array_diff(array_keys($body), ['server_id', 'operation', 'request_id', 'payload'])) {
            throw new \InvalidArgumentException('Unbekannte Felder. Rollen und Prozesspfade werden nicht angenommen.');
        }
        foreach (['server_id', 'operation', 'request_id'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                throw new \InvalidArgumentException('Befehl unvollständig.');
            }
        }
        if (isset($body['payload']) && !$body['payload'] instanceof \stdClass) { throw new \InvalidArgumentException('Auftragsnutzdaten müssen ein Objekt sein.'); }
        return $this->json(['job' => JobRepository::public($this->service->submit($body['server_id'], $body['operation'], $body['request_id'], $this->principal(), $body['payload'] ?? null))], 202);
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
            $listener = $this->signals->listen();
            // In-process CLI requests can inspect the initial events; real HTTP keeps one live channel.
            $deadline = microtime(true) + (PHP_SAPI === 'cli' ? .05 : 300);
            $heartbeat = 0.0; $notifications = [['topic'=>'snapshot','server_id'=>null]]; $readJobs = true;
            try { while (!connection_aborted() && microtime(true) < $deadline) {
                try {
                    [$current, $currentScope] = $this->identity->resolve($request);
                    $this->policy->require($principal, 'jobs.read');
                    if ($current->claim() !== $principal->claim() || $currentScope !== $scope) { throw new AccessDeniedException(); }
                }
                catch (AccessDeniedException) {
                    yield new ServerEvent('Freigabe geändert. Bitte neu laden.', type: 'access-revoked'); return;
                }
                if (microtime(true) >= $heartbeat) {
                    $this->reconciler->reconcile();
                    yield new ServerEvent('', comment: 'heartbeat');
                    $heartbeat = microtime(true)+15; $readJobs = true;
                }
                do {
                $events = $readJobs ? $this->jobs->events($after) : [];
                foreach ($events as $event) {
                    $after = (int) $event['sequence'];
                    $job = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
                    if ($this->policy->visible($principal, $job)) {
                        yield new ServerEvent(json_encode(JobRepository::public($job), JSON_THROW_ON_ERROR), type: 'job', id: (string) $after);
                    }
                }
                } while (count($events) === 100);
                foreach ($notifications as $change) {
                    $topic = $change['topic']; $id = $change['server_id'] ?? null;
                    if (in_array($topic, ['jobs','access','game-event'], true)) { continue; }
                    if ($id !== null && !$principal->allows('status.read', $id)) { continue; }
                    if ($topic === 'logs' && !$principal->allows('logs.read', $id)) { continue; }
                    if ($topic === 'community' && !$principal->allows('community.read')) { continue; }
                    yield new ServerEvent(json_encode($change, JSON_THROW_ON_ERROR), type:'change');
                }
                $notifications = $this->signals->wait($listener, max(0,min($heartbeat,$deadline)-microtime(true)));
                $readJobs = in_array('jobs', array_column($notifications, 'topic'), true);
            } } finally { $this->signals->close($listener); }
        });
    }
}
