<?php
declare(strict_types=1);
namespace App\Http;

use App\Access\Principal;
use App\Community\ChronicleReader;
use App\Community\ChronicleWriter;
use App\Community\ChronicleMedia;
use App\Community\ChronicleExport;
use App\Storage\PrivateFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChronicleController extends AbstractController
{
    public function __construct(private readonly ChronicleReader $reader, private readonly ChronicleWriter $writer,
        private readonly ChronicleMedia $media, private readonly ChronicleExport $export) {}
    private function actor(): Principal { $actor = $this->getUser(); assert($actor instanceof Principal); return $actor; }
    #[Route('/api/community', methods: ['GET'])]
    public function page(Request $request): JsonResponse { return $this->json($this->reader->page($this->actor(), $request->query->all())); }
    #[Route('/api/community', methods: ['POST'])]
    public function save(Request $request): JsonResponse { return $this->json($this->writer->save($this->actor(), PrivateFiles::object($request->getContent()))); }
    #[Route('/api/community/options', methods: ['GET'])]
    public function options(): JsonResponse { return $this->json($this->reader->options($this->actor())); }
    #[Route('/api/community/event', methods: ['GET'])]
    public function detail(Request $request): JsonResponse { return $this->json($this->reader->detail($this->actor(), $request->query->getString('id'))); }
    #[Route('/api/community/story', methods: ['GET'])]
    public function story(Request $request): JsonResponse { return $this->json($this->reader->story($this->actor(), $request->query->all())); }
    #[Route('/api/community/media', methods: ['GET'])]
    public function media(Request $request): Response
    {
        $revision = $request->query->has('revision') ? $request->query->getString('revision') : null;
        return new Response($this->media->get($this->actor(), $request->query->getString('event'), $revision), headers: ['Content-Type' => 'image/jpeg']);
    }
    #[Route('/api/community/export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        return new Response($this->export->archive($this->actor(), $request->query->all()), headers: ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="sturmklingen-chronicle.zip"']);
    }
}
