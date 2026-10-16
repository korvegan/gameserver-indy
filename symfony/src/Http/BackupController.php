<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\Principal;
use App\Backup\WorldBackups;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BackupController extends AbstractController
{
    public function __construct(private readonly WorldBackups $backups) {}
    #[Route('/api/backups', methods: ['GET'])]
    public function view(Request $request): JsonResponse
    {
        $actor = $this->getUser(); assert($actor instanceof Principal);
        $id = $request->query->get('server');
        if (!is_string($id) || $id === '') { throw new \InvalidArgumentException('Serverprofil ausdrücklich auswählen.'); }
        if ($request->query->get('recovery') === 'true') { return $this->json(['preview' => $this->backups->recoveryPreview($actor, $id)]); }
        $backup = $request->query->get('id');
        return $this->json($backup !== null ? ['preview' => $this->backups->preview($actor, $id, $backup)] : $this->backups->view($actor, $id));
    }
}
