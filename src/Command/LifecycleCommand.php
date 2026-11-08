<?php

declare(strict_types=1);

namespace ModxInstaller\Command;

use ModxInstaller\{Docker, Project, ProjectLock};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LifecycleCommand extends Command
{
    public function __construct(private readonly string $operation) { parent::__construct($operation); }

    protected function configure(): void
    {
        $this->setDescription(match ($this->operation) {
            'up' => 'Start containers and finish a pending installation.',
            'down' => 'Stop and remove containers while preserving the database.',
            'logs' => 'Show container logs.',
        })->addOption('project-dir', 'p', InputOption::VALUE_REQUIRED, 'Project directory (default: discover from current directory)');
        if ($this->operation === 'logs') {
            $this->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow log output (Ctrl+C to stop)')
                ->addOption('tail', null, InputOption::VALUE_REQUIRED, 'Lines per service', '100')
                ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Filter: web, php or db');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = Project::find($input->getOption('project-dir') ?? getcwd());
        $docker = new Docker();
        $io = new SymfonyStyle($input, $output);
        if ($this->operation === 'up') {
            $installed = $docker->up($project, $output);
            NewCommand::showAccess($project, $io, $installed);
        } elseif ($this->operation === 'down') {
            $lock = new ProjectLock($project->directory);
            $docker->compose($project, ['down'], $output);
            $io->success('Containers stopped. Database volume preserved.');
        } else {
            $tail = $input->getOption('tail');
            $service = $input->getOption('service');
            if (!ctype_digit($tail) || (int)$tail > 100000) {
                throw new \InvalidArgumentException('--tail must be between 0 and 100000.');
            }
            if ($service !== null && !in_array($service, ['web', 'php', 'db'], true)) {
                throw new \InvalidArgumentException('--service must be web, php or db.');
            }
            $args = ['logs', '--tail', $tail];
            if ($input->getOption('follow')) { $args[] = '--follow'; }
            if ($service !== null) { $args[] = $service; }
            $docker->compose($project, $args, $output, null, null);
        }
        return Command::SUCCESS;
    }
}
