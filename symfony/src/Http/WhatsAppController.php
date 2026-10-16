<?php
declare(strict_types=1);
namespace App\Http;
use App\Access\Principal;
use App\Storage\PrivateFiles;
use App\WhatsApp\WhatsApp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WhatsAppController extends AbstractController
{
    public function __construct(private readonly WhatsApp $whatsapp) {}
    private function actor(): Principal { $actor = $this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/whatsapp',methods:['GET'])]
    public function view(): JsonResponse { return $this->json($this->whatsapp->view($this->actor())); }
    #[Route('/api/whatsapp/groups',methods:['GET'])]
    public function groups(): JsonResponse { return $this->json(['groups'=>$this->whatsapp->groups($this->actor())]); }
    #[Route('/api/whatsapp/qr',methods:['GET'])]
    public function qr(): Response { return new Response($this->whatsapp->qr($this->actor()),200,['Content-Type'=>'image/png']); }
    #[Route('/api/whatsapp/settings',methods:['POST'])]
    public function settings(Request $request): JsonResponse { return $this->json($this->whatsapp->configure($this->actor(),PrivateFiles::object($request->getContent()))); }
    #[Route('/api/whatsapp/connect',methods:['POST'])]
    #[Route('/api/whatsapp/disconnect',methods:['POST'])]
    public function connection(Request $request): JsonResponse
    {
        $this->whatsapp->owner($this->actor());
        if (get_object_vars(PrivateFiles::object($request->getContent())) !== []) { throw new \InvalidArgumentException('Unerwartete WhatsApp-Parameter.'); }
        return $this->json($this->whatsapp->connection($this->actor(),$request->getPathInfo() === '/api/whatsapp/connect'));
    }
    #[Route('/api/whatsapp/send',methods:['POST'])]
    public function send(Request $request): JsonResponse
    {
        $this->whatsapp->owner($this->actor()); $body = PrivateFiles::object($request->getContent());
        if (count(get_object_vars($body)) !== 2 || !is_string($body->server_id ?? null) || !is_int($body->revision ?? null)) { throw new \InvalidArgumentException('Bitte Serverprofil und Einstellungsrevision ausdrücklich auswählen.'); }
        return $this->json($this->whatsapp->send($this->actor(),$body->server_id,$body->revision));
    }
}
