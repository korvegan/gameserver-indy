<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\ReleasePackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:release:build',description:'Geprüften Commit mit lockgleichen lokalen Bibliotheken als privates Releasepaket erstellen; nichts aktivieren.')]
final class ReleaseBuildCommand extends Command
{
    public function __construct(private readonly ReleasePackage $packages) { parent::__construct(); }
    protected function configure(): void
    {
        foreach (['source','destination','commit'] as $name) { $this->addArgument($name,InputArgument::REQUIRED); }
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $result=$this->packages->build($input->getArgument('source'),$input->getArgument('destination'),$input->getArgument('commit'));
            $output->writeln('Release '.$result['commit'].' geprüft: '.$result['files'].' Dateien. Nicht aktiviert.'); return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Release nicht erstellt. Commit, sauberen Checkout, lockgleiche Bibliotheken und neues getrenntes Ziel prüfen.</error>'); return Command::FAILURE; }
    }
}
