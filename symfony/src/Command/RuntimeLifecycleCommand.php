<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\RuntimeLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'indy:runtime', description: 'Verwaltungslauf unter der eigenen Supervisor-Sperre öffnen oder schließen.')]
final class RuntimeLifecycleCommand extends Command
{
    public function __construct(private readonly RuntimeLifecycle $lifecycle) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('action', InputArgument::REQUIRED, 'open oder close'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('action') === 'open') { $this->lifecycle->open(); }
        elseif ($input->getArgument('action') === 'close') { $this->lifecycle->close(); }
        else { $output->writeln('<error>open oder close angeben.</error>'); return Command::INVALID; }
        $output->writeln('Verwaltungslauf abgeglichen. Wartende Altbefehle werden nicht wiederholt.'); return Command::SUCCESS;
    }
}
