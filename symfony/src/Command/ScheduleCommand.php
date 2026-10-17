<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\RuntimeLifecycle;
use App\Integration\MetadataScheduler;
use App\WhatsApp\WhatsApp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:schedule',description:'Einzelnen begrenzten Metadaten-/WhatsApp-Planer im aktuellen Verwaltungslauf betreiben.')]
final class ScheduleCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping=false;
    public function __construct(private readonly RuntimeLifecycle $lifecycle,private readonly MetadataScheduler $metadata,private readonly WhatsApp $whatsapp,
        private readonly \App\Runtime\SliceRuntime $runtime,private readonly \App\Community\Challenges $challenges,private readonly \App\Runtime\MaintenancePlans $maintenance) { parent::__construct(); }
    public function getSubscribedSignals(): array { return defined('SIGTERM') ? [SIGTERM,SIGINT] : []; }
    public function handleSignal(int $signal,int|false $previousExitCode=0): int|false { $this->stopping=true; return false; }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $lock=(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($this->runtime->directory.'/locks')))->createLock('scheduler-loop');
        if (!$lock->acquire()) return Command::FAILURE;
        try {
          $epoch=$this->lifecycle->epoch();
          while (!$this->stopping) {
            try { if ($this->lifecycle->epoch()!==$epoch) return Command::SUCCESS; }
            catch (\DomainException) { return Command::SUCCESS; }
            try { $this->metadata->tick(); } catch (\Throwable) { $output->writeln('Metadatenplaner konnte diesen Schritt nicht abschließen.'); }
            try { $this->whatsapp->tick(); } catch (\Throwable) { $output->writeln('WhatsApp-Planerschritt fehlgeschlagen; kein erneuter unklarer Versand.'); }
            try { $this->challenges->reconcile(); } catch (\Throwable) { $output->writeln('Sammelziel-Abschluss noch nicht in der Chronik bestätigt.'); }
            try { $this->maintenance->tick(); } catch (\Throwable) { $output->writeln('Wartungsplan wartet weiterhin auf Auftragsannahme.'); }
            $next=microtime(true)+15;
            while (!$this->stopping && microtime(true)<$next) usleep(100000);
          }
          return Command::SUCCESS;
        } finally { $lock->release(); }
    }
}
