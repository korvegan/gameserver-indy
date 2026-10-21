<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Integration\Connections;
use App\Integration\Discord;
use App\Integration\SteamUpdates;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Runtime\ProcessObservation;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class IntegrationController extends AbstractController
{
    public function __construct(private readonly AccessPolicy $policy, private readonly JobService $jobs,
        private readonly SteamUpdates $updates, private readonly Connections $connections, private readonly Discord $discord,
        private readonly ProcessObservation $observations, private readonly \App\Integration\DiscordStatusCard $cards) {}
    private function actor(): Principal { $actor = $this->getUser(); assert($actor instanceof Principal); return $actor; }
    private function id(mixed $id): string { return is_string($id) && $id !== '' ? $id : throw new \InvalidArgumentException('Serverprofil ausdrücklich auswählen.'); }
    private function body(Request $request, array $keys): \stdClass
    {
        $body = PrivateFiles::object($request->getContent());
        if (array_diff(array_keys(get_object_vars($body)), $keys)) { throw new \InvalidArgumentException('Unbekannte Anfragefelder.'); }
        return $body;
    }
    #[Route('/api/updates', methods: ['GET'])]
    public function updates(Request $request): JsonResponse
    {
        $id = $this->id($request->query->get('server')); $this->policy->require($this->actor(), 'status.read', $id);
        return $this->json(['update' => $this->updates->view($id, $this->observations->view($id))]);
    }
    #[Route('/api/connections', methods: ['GET'])]
    public function connections(Request $request): JsonResponse
    {
        $id = $this->id($request->query->get('server')); $this->policy->require($this->actor(), 'status.read', $id);
        return $this->json(['connection' => $this->connections->view($id)]);
    }
    #[Route('/api/updates/check', methods: ['POST'])]
    #[Route('/api/connections/refresh', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'request_id']);
        $operation = $request->getPathInfo() === '/api/updates/check' ? 'check_updates' : 'refresh_connections';
        return $this->json(['job' => JobRepository::public($this->jobs->submit($this->id($body->server_id ?? null), $operation,
            $this->id($body->request_id ?? null), $this->actor()))], 202);
    }
    #[Route('/api/discord', methods: ['GET'])]
    public function discord(Request $request): JsonResponse
    {
        $id = $this->id($request->query->get('server')); return $this->json(['discord' => $this->discord->view($this->actor(), $id),
            'card'=>$this->cards->view($this->actor(),$id), 'join_message' => $this->connections->details($id)['share_message']]);
    }
    #[Route('/api/discord/settings', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'webhook_url', 'clear_webhook', 'auto_notify']); $id = $this->id($body->server_id ?? null); unset($body->server_id);
        return $this->json($this->discord->save($this->actor(), $id, $body));
    }
    #[Route('/api/discord/send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $body = $this->body($request, ['server_id', 'request_id', 'message']);
        return $this->json(['job' => JobRepository::public($this->jobs->submit($this->id($body->server_id ?? null), 'discord_send',
            $this->id($body->request_id ?? null), $this->actor(), (object) ['message' => $body->message ?? null]))], 202);
    }
    #[Route('/api/discord/card', methods: ['POST'])]
    public function card(Request $request): JsonResponse
    {
        $body=$this->body($request,['server_id','request_id','replace','action','generation']); $id=$this->id($body->server_id ?? null);
        if (($body->action ?? null)==='disable') {
            $this->cards->disable($this->actor(),$id); return $this->json(['message'=>'Automatische Statuskarte pausiert. Die vorhandene Nachricht bleibt erhalten.']);
        }
        if (($body->action ?? null)!=='create') { throw new \InvalidArgumentException('Kartenaktion auswählen.'); }
        return $this->json(['job'=>JobRepository::public($this->jobs->submit($id,'discord_card_create',$this->id($body->request_id ?? null),
            $this->actor(),(object)['replace'=>$body->replace ?? false,'generation'=>$body->generation ?? null]))],202);
    }
}
