<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\Principal;
use App\Instance\InstanceConfiguration;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigurationController extends AbstractController
{
    public function __construct(private readonly InstanceRegistry $registry, private readonly InstanceConfiguration $configuration,
        private readonly SliceRuntime $runtime, private readonly JobService $jobs) {}
    private function actor(): Principal { $actor = $this->getUser(); assert($actor instanceof Principal); return $actor; }
    private function body(Request $request, array $fields): \stdClass
    {
        $body = PrivateFiles::object($request->getContent());
        if (array_diff(array_keys(get_object_vars($body)), $fields)) { throw new \InvalidArgumentException('Unbekannte Anfragefelder.'); }
        return $body;
    }
    private function id(mixed $value): string
    {
        if (!is_string($value) || $value === '') { throw new \InvalidArgumentException('Das Serverprofil muss ausdrücklich ausgewählt sein.'); } return $value;
    }
    #[Route('/api/servers', methods: ['GET'])]
    public function catalog(): JsonResponse { return $this->json($this->registry->catalog($this->actor())); }
    #[Route('/api/servers', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        return $this->json($this->registry->register($this->actor(), PrivateFiles::object($request->getContent())), 201);
    }
    #[Route('/api/connections/settings', methods: ['POST'])]
    public function connection(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'public_host']);
        return $this->json($this->registry->saveConnection($this->actor(), $this->id($body->server_id ?? null), $body->public_host ?? null));
    }
    #[Route('/api/config', methods: ['GET'])]
    public function view(Request $request): JsonResponse
    {
        return $this->json($this->configuration->view($this->actor(), $this->id($request->query->get('server'))));
    }
    #[Route('/api/config', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'config', 'revision', 'request_id']);
        if (!($body->config ?? null) instanceof \stdClass || !is_string($body->revision ?? null)) { throw new \InvalidArgumentException('Konfigurationsobjekt und Dateistand erforderlich.'); }
        if ($this->runtime->driver === 'managed') {
            $job = $this->jobs->submit($this->id($body->server_id ?? null), 'config', $this->id($body->request_id ?? null), $this->actor(),
                (object) ['config' => $body->config, 'revision' => $body->revision]);
            return $this->json(['job' => JobRepository::public($job)], 202);
        }
        return $this->json($this->configuration->save($this->actor(), $this->id($body->server_id ?? null), $body->config, $body->revision));
    }
    #[Route('/api/setup', methods: ['POST'])]
    public function setup(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'request_id']);
        if ($this->runtime->driver === 'managed') {
            return $this->json(['job' => JobRepository::public($this->jobs->submit($this->id($body->server_id ?? null), 'setup',
                $this->id($body->request_id ?? null), $this->actor()))], 202);
        }
        return $this->json($this->configuration->setup($this->actor(), $this->id($body->server_id ?? null)));
    }
}
