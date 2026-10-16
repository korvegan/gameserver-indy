<?php
declare(strict_types=1);

namespace App\Http;

use App\Job\JobRepository;
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

final class SliceController extends AbstractController
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs,
        private readonly JobService $service, private readonly ServerControl $control) {}

    #[Route('/', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('slice.html.twig', ['token' => $this->runtime->csrfToken(), 'role' => $this->runtime->role,
            'driver' => $this->runtime->driver, 'servers' => SliceRuntime::SERVERS]);
    }

    #[Route('/api/session', methods: ['GET'])]
    public function session(): JsonResponse
    {
        return $this->json(['token' => $this->runtime->csrfToken(), 'role' => $this->runtime->role,
            'isolated' => true, 'driver' => $this->runtime->driver, 'servers' => SliceRuntime::SERVERS]);
    }

    #[Route('/api/status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $serverId = (string) $request->query->get('server', 'demo-a');
        return $this->json(['server_id' => $serverId, 'status' => $this->control->status($serverId)]);
    }

    #[Route('/api/jobs', methods: ['GET'])]
    public function jobs(Request $request): JsonResponse
    {
        $id = $request->query->get('id');
        return $this->json($id === null ? $this->jobs->snapshot() : ['job' => $this->jobs->get($id)]);
    }

    #[Route('/api/jobs', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $body = $request->toArray();
        if (array_diff(array_keys($body), ['server_id', 'operation', 'request_id'])) {
            throw new \InvalidArgumentException('Unbekannte Felder. Rollen und Prozesspfade werden nicht angenommen.');
        }
        foreach (['server_id', 'operation', 'request_id'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                throw new \InvalidArgumentException('Befehl unvollständig.');
            }
        }
        return $this->json(['job' => $this->service->submit($body['server_id'], $body['operation'], $body['request_id'])], 202);
    }

    #[Route('/api/job-events', methods: ['GET'])]
    public function events(Request $request): EventStreamResponse
    {
        $cursor = $request->headers->get('Last-Event-ID', (string) $request->query->get('after', '0'));
        if (!preg_match('/^[0-9]{1,18}$/D', $cursor)) {
            throw new \InvalidArgumentException('Ungültiger Ereignisstand.');
        }
        $after = (int) $cursor;
        return new EventStreamResponse(function () use ($after): iterable {
            $deadline = microtime(true) + 10;
            $heartbeat = 0.0;
            while (!connection_aborted() && microtime(true) < $deadline) {
                foreach ($this->jobs->events($after) as $event) {
                    $after = (int) $event['sequence'];
                    yield new ServerEvent($event['payload'], type: 'job', id: (string) $after);
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
