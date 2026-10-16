<?php
declare(strict_types=1);
namespace App\Http;
use App\Job\JobRepository;
use App\Runtime\PerformanceHistory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PerformanceController extends AbstractController
{
    public function __construct(private readonly PerformanceHistory $history,private readonly JobRepository $jobs) {}
    private function server(Request $request,string $permission): string
    {
        $id=$request->query->get('server'); if (!is_string($id) || $id==='') { throw new \InvalidArgumentException('Serverprofil auswählen.'); }
        $this->denyAccessUnlessGranted($permission,$id); return $id;
    }
    #[Route('/api/performance',methods:['GET'])]
    public function view(Request $request): JsonResponse
    {
        $id=$this->server($request,'status.read'); $data=$this->history->view($id);
        foreach ($this->jobs->recent([$id]) as $job) {
            if (!in_array($job['operation'],['start','stop','restart','backup','backup_restore','check_updates','config'],true)) continue;
            $data['markers'][]=['id'=>$job['id'],'at'=>(new \DateTimeImmutable($job['updated_at']))->getTimestamp(),
                'type'=>'job','label'=>$job['operation'].' · '.$job['state']];
        }
        usort($data['markers'],fn($a,$b)=>$b['at']<=>$a['at']); return $this->json($data);
    }
    #[Route('/api/performance/markers',methods:['POST'])]
    public function mark(Request $request): JsonResponse
    {
        $id=$this->server($request,'config.write'); $value=$request->toArray();
        if (array_diff(array_keys($value),['note']) || !is_string($value['note'] ?? null)) { throw new \InvalidArgumentException('Notiz für Markierung erforderlich.'); }
        $this->history->mark($id,$value['note']); return $this->json(['message'=>'Ruckler-Zeitpunkt festgehalten.']);
    }
}
