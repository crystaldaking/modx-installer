<?php

declare(strict_types=1);

namespace ModxInstaller;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class Docker
{
    public function check(): array
    {
        $results = [];
        foreach (['Docker daemon' => ['docker', 'info', '--format', '{{.ServerVersion}}'], 'Docker Compose' => ['docker', 'compose', 'version', '--short']] as $label => $command) {
            try {
                $process = new Process($command);
                $process->setTimeout(15);
                $process->run();
                $ok = $process->isSuccessful();
                $detail = trim($process->getOutput());
                if ($label === 'Docker Compose' && $ok && version_compare(ltrim($detail, 'v'), '2.20.0', '<')) {
                    $ok = false;
                    $detail = 'Compose >=2.20 is required.';
                }
                $results[$label] = ['ok' => $ok, 'detail' => $ok ? $detail : ($detail ?: 'Unavailable. Install/start Docker with Compose >=2.20.')];
            } catch (\Throwable $e) {
                $results[$label] = ['ok' => false, 'detail' => $e->getMessage()];
            }
        }
        return $results;
    }

    public function requireAvailable(): void
    {
        foreach ($this->check() as $label => $result) {
            if (!$result['ok']) {
                throw new \RuntimeException($label.': '.$result['detail']);
            }
        }
    }

    public function compose(Project $project, array $arguments, OutputInterface $output, ?string $stdin = null, ?float $timeout = 900): void
    {
        $env = $project->env();
        $command = ['docker', 'compose', '--project-directory', $project->directory, '--env-file', $project->directory.'/.env', '-f', $project->directory.'/compose.yaml', '--project-name', $project->metadata['name'], ...$arguments];
        $process = new Process($command, $project->directory, $env, $stdin, $timeout);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Docker Compose failed (exit '.$process->getExitCode().'). Project files and database have been preserved. Inspect modx logs; rerun modx up after fixing the cause.');
        }
    }

    public function up(Project $project, OutputInterface $output): bool
    {
        $this->requireAvailable();
        $this->compose($project, ['up', '-d', '--build', '--wait', '--wait-timeout', '180', 'db', 'php'], $output);
        $installed = !is_file($project->directory.'/.modx-installed');
        if ($installed) {
            $payload = json_encode($project->env(), JSON_THROW_ON_ERROR);
            if (!is_file($project->directory.'/app/core/config/config.inc.php')) {
                $this->compose($project, ['exec', '-T', 'php', 'php', '/opt/modx/install.php'], $output, $payload);
            }
            $this->compose($project, ['exec', '-T', 'php', 'php', '/opt/modx/verify.php'], $output, $payload);
            (new \Symfony\Component\Filesystem\Filesystem())->dumpFile($project->directory.'/.modx-installed', "Installation and administrator verified.\n");
        }
        $this->compose($project, ['up', '-d', '--wait', '--wait-timeout', '60', 'web'], $output);
        return $installed;
    }
}
