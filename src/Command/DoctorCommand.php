<?php

declare(strict_types=1);

namespace ModxInstaller\Command;

use ModxInstaller\{Docker, Project};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('doctor')->setDescription('Check PHP, Docker, Compose and optional project configuration.')
            ->addOption('project-dir', 'p', InputOption::VALUE_REQUIRED, 'Also validate this project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checks = ['PHP >=8.2' => ['ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION]];
        foreach (['curl', 'dom', 'zip'] as $ext) {
            $checks['ext-'.$ext] = ['ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'Loaded' : 'Install this PHP extension'];
        }
        $checks += (new Docker())->check();
        if (($directory = $input->getOption('project-dir')) !== null) {
            try {
                $project = Project::find($directory);
                $project->env();
                foreach (['compose.yaml', 'app/index.php', 'docker/php/Dockerfile', 'docker/nginx/default.conf', 'docker/install.php', 'docker/verify.php'] as $file) {
                    if (!is_file($project->directory.'/'.$file)) {
                        throw new \RuntimeException('Missing '.$file);
                    }
                }
                $checks['Project'] = ['ok' => true, 'detail' => $project->directory];
            } catch (\Throwable $e) {
                $checks['Project'] = ['ok' => false, 'detail' => $e->getMessage()];
            }
        }
        $ok = true;
        foreach ($checks as $label => $result) {
            $ok = $ok && $result['ok'];
            $io->text(($result['ok'] ? '[OK] ' : '[FAIL] ').$label.': '.$result['detail']);
        }
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
