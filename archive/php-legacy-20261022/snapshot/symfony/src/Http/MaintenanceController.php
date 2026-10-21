<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Runtime\MaintenancePlans;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final class MaintenanceController extends AbstractController
{
    public function __construct(private readonly MaintenancePlans $plans) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/maintenance/{server}',methods:['GET'])]
    public function view(string $server): JsonResponse { return $this->json($this->plans->view($this->actor(),$server)); }
    #[Route('/api/maintenance/{server}',methods:['POST'])]
    public function save(string $server,Request $request): JsonResponse { return $this->json($this->plans->save($this->actor(),$server,PrivateFiles::object($request->getContent()))); }
}
