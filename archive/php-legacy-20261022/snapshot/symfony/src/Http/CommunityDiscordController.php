<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Community\DiscordPublication;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Job\JobService;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class CommunityDiscordController extends AbstractController
{
    public function __construct(private readonly DiscordPublication $publication,private readonly InstanceRegistry $registry,private readonly JobService $jobs) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/community/discord',methods:['GET'])]
    public function view(Request $request): JsonResponse { return $this->json($this->publication->view($this->actor(),$request->query->get('event'))); }
    #[Route('/api/community/discord/settings',methods:['POST'])]
    public function save(Request $request): JsonResponse { return $this->json($this->publication->configure($this->actor(),PrivateFiles::object($request->getContent()))); }
    #[Route('/api/community/discord/publish',methods:['POST'])]
    public function publish(Request $request): JsonResponse
    {
        $body=PrivateFiles::object($request->getContent()); $requestId=$body->request_id ?? null; unset($body->request_id);
        if (!is_string($requestId)) { throw new \InvalidArgumentException('Anfragekennung erforderlich.'); }
        $id=$this->registry->settings()->servers[0]->id;
        return $this->json(['job'=>JobRepository::public($this->jobs->submit($id,'community_discord',$requestId,$this->actor(),$body))],202);
    }
}
