<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Community\Challenges;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class ChallengeController extends AbstractController
{
    public function __construct(private readonly Challenges $challenges) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/community/challenges',methods:['GET'])]
    public function view(): JsonResponse { return $this->json($this->challenges->view($this->actor())); }
    #[Route('/api/community/campaigns',methods:['POST'])]
    public function campaign(Request $request): JsonResponse { return $this->json($this->challenges->createCampaign($this->actor(),PrivateFiles::object($request->getContent()))); }
    #[Route('/api/community/challenges',methods:['POST'])]
    public function goal(Request $request): JsonResponse { return $this->json($this->challenges->createGoal($this->actor(),PrivateFiles::object($request->getContent()))); }
    #[Route('/api/community/challenges/evidence',methods:['POST'])]
    public function observe(Request $request): JsonResponse
    {
        $body=$request->toArray();
        if (array_diff(array_keys($body),['goal_id','member_id','state','occurred_at','note']) || array_filter($body,fn($value)=>!is_string($value))) { throw new \InvalidArgumentException('Nachweisfelder ungültig.'); }
        return $this->json($this->challenges->observe($this->actor(),$body['goal_id'] ?? '',$body['member_id'] ?? '',$body['state'] ?? '',$body['occurred_at'] ?? '',$body['note'] ?? ''));
    }
    #[Route('/api/community/challenges/withdraw',methods:['POST'])]
    public function withdraw(Request $request): JsonResponse
    {
        $body=$request->toArray();
        if (array_diff(array_keys($body),['goal_id','reason']) || !is_string($body['goal_id'] ?? null) || !is_string($body['reason'] ?? null)) { throw new \InvalidArgumentException('Abschluss und Begründung angeben.'); }
        $this->challenges->withdraw($this->actor(),$body['goal_id'],$body['reason']); return $this->json(['message'=>'Abschluss mit Begründung zurückgezogen. Sein Verlauf bleibt erhalten.']);
    }
}
