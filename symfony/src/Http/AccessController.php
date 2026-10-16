<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Runtime\SliceRuntime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AccessController extends AbstractController
{
    public function __construct(private readonly AccessStore $store) {}
    #[Route('/api/access', methods: ['GET'])]
    public function view(): JsonResponse
    {
        $this->denyAccessUnlessGranted('access.manage');
        return $this->json($this->store->view() + ['servers' => array_map(
            fn ($id, $label) => ['id' => $id, 'label' => $label], array_keys(SliceRuntime::SERVERS), SliceRuntime::SERVERS)]);
    }
    #[Route('/api/access/users', methods: ['POST'])]
    public function user(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('access.manage');
        $actor = $this->getUser(); assert($actor instanceof Principal);
        return $this->json($this->store->saveUser($actor, $request->toArray(), array_keys(SliceRuntime::SERVERS)));
    }
    #[Route('/api/access/settings', methods: ['POST'])]
    public function settings(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('access.manage');
        $actor = $this->getUser(); assert($actor instanceof Principal);
        return $this->json($this->store->configure($actor, $request->toArray()));
    }
}
