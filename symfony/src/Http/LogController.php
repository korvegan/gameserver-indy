<?php
declare(strict_types=1);
namespace App\Http;
use App\Log\ServerLogs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogController extends AbstractController
{
    public function __construct(private readonly ServerLogs $logs) {}
    #[Route('/api/logs',methods:['GET'])]
    public function logs(Request $request): JsonResponse
    {
        return $this->json($this->read($request));
    }
    #[Route('/api/logs/export',methods:['GET'])]
    public function export(Request $request): Response
    {
        $data = $this->read($request);
        $text = "GameServer Indy · bereinigter Diagnoseausschnitt\nProfil: ".$data['profile_label']." (".$data['server_id'].")\nQuelle: ".$data['source']
            ."\nDatei: ".($data['file'] ?? 'nicht vorhanden')."\nDateistand: ".($data['updated_at'] ?? 'unbekannt')
            ."\nProzessstart: ".($data['process_started_at'] ?? 'unbekannt')."\nLogstartzuordnung: nicht automatisch bestätigt\nAbruf: ".$data['read_at']
            ."\nUmfang: höchstens 4000 Zeilen / 256 KiB, ohne Ansichtsfilter\n\n".$data['text']."\n";
        return new Response($text,200,['Content-Type'=>'text/plain; charset=utf-8',
            'Content-Disposition'=>'attachment; filename="gameserver-diagnose.txt"','Cache-Control'=>'private, no-store']);
    }
    private function read(Request $request): array
    {
        $id = $request->query->get('server');
        if (!is_string($id) || $id === '') { throw new \InvalidArgumentException('Serverprofil ausdrücklich auswählen.'); }
        $this->denyAccessUnlessGranted('logs.read',$id);
        return $this->logs->read($id,$request->query->get('source'));
    }
}
