<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\StateSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:state:snapshot',description:'Gestoppte Altverwaltung in ein neues geprüftes privates Paket kopieren; nichts aktivieren.')]
final class StateSnapshotCommand extends Command
{
    public function __construct(private readonly StateSnapshot $snapshot) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('source',InputArgument::REQUIRED)->addArgument('destination',InputArgument::REQUIRED)
            ->addOption('port',null,InputOption::VALUE_REQUIRED,'Lokaler Port der ursprünglichen Verwaltung','8765');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $port=filter_var($input->getOption('port'),FILTER_VALIDATE_INT);
            if (!is_int($port)) { throw new \DomainException('Ungültiger Originalport.'); }
            $result=$this->snapshot->copy($input->getArgument('source'),$input->getArgument('destination'),$port);
            $output->writeln('Privates Verwaltungspaket geprüft: '.$result['profiles'].' Profile, '.$result['files'].' Dateien. Nicht aktiviert.');
            return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Verwaltungspaket nicht übernommen. Gestoppte Originalverwaltung, vollständigen Bestand und neues getrenntes Ziel prüfen.</error>'); return Command::FAILURE; }
    }
}
