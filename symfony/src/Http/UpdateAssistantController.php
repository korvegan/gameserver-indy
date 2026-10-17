<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Integration\UpdateAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
final class UpdateAssistantController extends AbstractController
{
    public function __construct(private readonly UpdateAssistant $assistant) {}
    #[Route('/api/update-assistant/{server}',methods:['GET'])]
    public function view(string $server): JsonResponse { $actor=$this->getUser(); assert($actor instanceof Principal); return $this->json($this->assistant->view($actor,$server)); }
}
