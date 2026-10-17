<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Integration\ModCatalog;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class ModController extends AbstractController
{
    public function __construct(private readonly ModCatalog $catalog) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/mods/{game}',methods:['GET'])]
    public function view(string $game): JsonResponse { return $this->json($this->catalog->view($this->actor(),$game)); }
    #[Route('/api/mods/{game}',methods:['POST'])]
    public function save(string $game,Request $request): JsonResponse { return $this->json($this->catalog->save($this->actor(),$game,PrivateFiles::object($request->getContent()))); }
}
