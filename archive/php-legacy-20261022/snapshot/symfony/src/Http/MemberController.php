<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\ListenerIdentity;
use App\Access\Principal;
use App\Community\MemberProfiles;
use App\Community\SteamIdentity;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MemberController extends AbstractController
{
    public function __construct(private readonly MemberProfiles $members,private readonly SteamIdentity $steam,private readonly ListenerIdentity $identity) {}
    private function actor(): Principal { $actor=$this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/community/members',methods:['GET'])]
    public function view(): JsonResponse { $this->denyAccessUnlessGranted('community.read'); return $this->json($this->members->view($this->actor())); }
    #[Route('/api/community/members',methods:['POST'])]
    public function save(Request $request): JsonResponse { return $this->json($this->members->save($this->actor(),PrivateFiles::object($request->getContent()))); }
    #[Route('/api/community/members/me',methods:['POST'])]
    public function own(Request $request): JsonResponse
    {
        $body=$request->toArray();
        if (array_diff(array_keys($body),['name','disconnect_steam'])) { throw new \InvalidArgumentException('Nur eigenes Profil bearbeiten.'); }
        if (isset($body['name'])) {
            if (!is_string($body['name'])) { throw new \InvalidArgumentException('Anzeigename erforderlich.'); }
            $this->members->rename($this->actor(),$body['name']);
        }
        if (($body['disconnect_steam'] ?? false)===true) { $this->members->steam($this->actor(),null); }
        return $this->json(['message'=>'Eigenes Profil gespeichert.']);
    }
    #[Route('/api/community/steam/begin',methods:['POST'])]
    public function begin(Request $request): JsonResponse
    {
        [$actor,$scope,$origin]=$this->identity->resolve($request); $data=$this->steam->begin($actor,$scope,$origin);
        $response=$this->json(['url'=>$data['url']]);
        $response->headers->setCookie(Cookie::create('indy_steam_link',$data['cookie'],time()+600,'/steam/callback',secure:str_starts_with($origin,'https:'),httpOnly:true,sameSite:'lax'));
        return $response;
    }
    #[Route('/steam/callback',methods:['GET'])]
    public function callback(Request $request): Response
    {
        [$actor,$scope,$origin]=$this->identity->resolve($request);
        $this->steam->complete($actor,$scope,$origin,$request->server->get('QUERY_STRING',''),$request->cookies->get('indy_steam_link',''));
        $response=new Response('<!doctype html><html lang="de"><meta charset="utf-8"><title>Steam verknüpft</title><h1>Steam-Konto verknüpft.</h1><p>Die Anmeldung bestätigt deine Steam-ID. Private Erfolge werden dadurch nicht freigegeben.</p><p><a href="/dashboard">Zurück zum Dashboard → Sturmklingen</a></p></html>');
        $response->headers->clearCookie('indy_steam_link','/steam/callback'); return $response;
    }
}
