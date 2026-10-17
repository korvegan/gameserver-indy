<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Integration\ModCatalog;
use App\Integration\ModProfiles;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class ModController extends AbstractController
{
    public function __construct(private readonly ModCatalog $catalog,private readonly ModProfiles $profiles) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/mods/{game}',methods:['GET'])]
    public function view(string $game): JsonResponse { return $this->json($this->catalog->view($this->actor(),$game)); }
    #[Route('/api/mods/{game}',methods:['POST'])]
    public function save(string $game,Request $request): JsonResponse { return $this->json($this->catalog->save($this->actor(),$game,PrivateFiles::object($request->getContent()))); }
    #[Route('/api/mods/{game}/profiles',methods:['GET'])]
    public function profiles(string $game): JsonResponse { return $this->json($this->profiles->view($this->actor(),$game)); }
    #[Route('/api/mods/{game}/profiles',methods:['POST'])]
    public function publish(string $game,Request $request): JsonResponse { return $this->json($this->profiles->publish($this->actor(),$game,PrivateFiles::object($request->getContent()))); }
    #[Route('/api/mods/{game}/profiles/{id}',methods:['POST'])]
    public function action(string $game,string $id,Request $request): JsonResponse { return $this->json($this->profiles->action($this->actor(),$game,$id,PrivateFiles::object($request->getContent()))); }
    #[Route('/api/mods/{game}/profiles/{id}/export',methods:['GET'])]
    public function export(string $game,string $id): Response { return new Response($this->profiles->export($this->actor(),$game,$id),headers:['Content-Type'=>'application/json','Content-Disposition'=>'attachment; filename="indy-mod-profile.json"']); }
    #[Route('/api/mods/{game}/profiles/{id}/compare/{base}',methods:['GET'])]
    public function compare(string $game,string $id,string $base): JsonResponse { return $this->json($this->profiles->compare($this->actor(),$game,$id,$base)); }
}
