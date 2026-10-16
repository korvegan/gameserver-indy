<?php
declare(strict_types=1);
namespace App\Http;
use App\Log\ServerLogs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LogController extends AbstractController
{
    public function __construct(private readonly ServerLogs $logs) {}
    #[Route('/api/logs',methods:['GET'])]
    public function logs(Request $request): JsonResponse
    {
        $id = $request->query->get('server');
        if (!is_string($id) || $id === '') { throw new \InvalidArgumentException('Serverprofil ausdrücklich auswählen.'); }
        $this->denyAccessUnlessGranted('logs.read',$id);
        return $this->json($this->logs->read($id,$request->query->get('source')));
    }
}
