<?php
declare(strict_types=1);

namespace App\Command;

use App\Access\AccessStore;
use App\Access\Principal;
use App\Access\SessionKeys;
use App\Job\JobRepository;
use App\Runtime\SliceRuntime;
use App\Runtime\WindowsStandinControl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

#[AsCommand(name: 'slice:init', description: 'Eigenen Testdatenordner, Auftragstabellen und optional Windows-Stellvertreter anlegen.')]
final class InitializeCommand extends Command
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly JobRepository $jobs,
        private readonly WindowsStandinControl $windows, private readonly AccessStore $access, private readonly SessionKeys $keys,
        #[Autowire(service: 'messenger.transport.async')] private readonly SetupableTransportInterface $transport)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runtime->initialize();
        $this->access->initialize();
        $this->keys->token(new Principal('local-owner', 'owner'), 'local-owner');
        $this->jobs->initialize();
        $this->transport->setup();
        if ($this->runtime->driver === 'windows') {
            $this->windows->prepare();
        }
        $output->writeln('Isolierter Symfony-Durchstich vorbereitet: '.$this->runtime->driver.'.');
        return Command::SUCCESS;
    }
}
