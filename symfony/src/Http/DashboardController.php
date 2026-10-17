<?php
declare(strict_types=1);
namespace App\Http;
use App\Dashboard\TechnicalEvents;
use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private readonly string $projectDirectory,private readonly GameCatalog $games,
        private readonly InstanceRegistry $registry,private readonly TechnicalEvents $events) {}
    #[Route('/dashboard',methods:['GET'])]
    public function dashboard(): Response { $this->denyAccessUnlessGranted('catalog.read'); return $this->render('dashboard.html.twig'); }
    #[Route('/{asset}',requirements:['asset'=>'(?:app|api|state|dom|forms|navigation|jobs|job-stream|log-lines)\.js|(?:style|serverzentrale)\.css|favicon\.svg|views/(?:server|workspace|logs|catalog|mods|community|updates|connection|chronicle-form|story|story-art|access|backups|whatsapp|performance|discord-card|members|challenges|community-discord)\.js|artwork/(?:enshrouded|aska)-(?:hero\.jpg|logo\.png)'],methods:['GET'])]
    public function asset(string $asset): Response
    {
        $path = dirname($this->projectDirectory).'/web/'.$asset;
        if (!is_file($path) || is_link($path)) { throw $this->createNotFoundException(); }
        $type = match (pathinfo($asset,PATHINFO_EXTENSION)) {
            'js'=>'text/javascript; charset=utf-8', 'css'=>'text/css; charset=utf-8',
            'jpg'=>'image/jpeg', 'png'=>'image/png', default=>'image/svg+xml',
        };
        return new Response(file_get_contents($path),200,['Content-Type'=>$type]);
    }
    #[Route('/api/games',methods:['GET'])]
    public function games(): JsonResponse { $this->denyAccessUnlessGranted('catalog.read'); return $this->json(['games'=>$this->games->entries($this->registry->settings()->catalog_games ?? [])]); }
    #[Route('/api/events',methods:['GET'])]
    public function events(Request $request): JsonResponse
    {
        $id = $request->query->get('server'); if (!is_string($id) || $id === '') { throw new \InvalidArgumentException('Serverprofil ausdrücklich auswählen.'); }
        $this->denyAccessUnlessGranted('config.read',$id); return $this->json(['events'=>$this->events->read($id)]);
    }
}
