<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Instance\Worlds;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
final class WorldController extends AbstractController
{
    public function __construct(private readonly Worlds $worlds) {}
    #[Route('/api/worlds/{server}',methods:['GET'])]
    public function view(string $server): JsonResponse { $actor=$this->getUser(); assert($actor instanceof Principal); return $this->json($this->worlds->view($actor,$server)); }
}
