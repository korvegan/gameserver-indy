<?php
declare(strict_types=1);

namespace App\Command;

use App\Runtime\SliceRuntime;
use App\Runtime\WindowsStandinControl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'slice:cleanup', description: 'Ausschließlich die beiden eigenen Windows-Testprozesse regulär beenden.')]
final class CleanupCommand extends Command
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly WindowsStandinControl $windows)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runtime->driver === 'windows') {
            foreach (array_keys(SliceRuntime::SERVERS) as $id) { $this->windows->execute($id, 'stop'); }
        }
        $output->writeln('Eigene Windows-Testprogramme beendet.');
        return Command::SUCCESS;
    }
}
