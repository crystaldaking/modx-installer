<?php

declare(strict_types=1);

namespace ModxInstaller\Command;

use ModxInstaller\{Docker, HttpClient, Project, Release, Scaffolder};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class NewCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('new')->setDescription('Create and install a local MODX 3 site.')
            ->addArgument('directory', InputArgument::REQUIRED, 'New or empty directory; use . for the current directory')
            ->addOption('modx-version', null, InputOption::VALUE_REQUIRED, 'Stable MODX version (also accepts --version=3.x.y-pl); defaults to latest')
            ->addOption('no-start', null, InputOption::VALUE_NONE, 'Download and prepare files without requiring Docker')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'HTTP port (default: first free port in 8080–8180)')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Initial administrator email', 'admin@example.test')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'MODX manager language', 'en')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Require this independently obtained SHA-256 of the official ZIP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getOption('admin-email');
        $language = $input->getOption('language');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9@._+-]+$/D', $email)) {
            throw new \InvalidArgumentException('Provide a valid --admin-email address (ASCII).');
        }
        if (!preg_match('/^[a-z]{2}(?:[-_][a-zA-Z]{2})?$/D', $language)) {
            throw new \InvalidArgumentException('Use a language code such as en or ru.');
        }
        $checksum = $input->getOption('sha256');
        if ($checksum !== null && !preg_match('/^[a-fA-F0-9]{64}$/D', $checksum)) {
            throw new \InvalidArgumentException('--sha256 must contain 64 hexadecimal characters.');
        }
        $version = $input->getOption('modx-version');
        if ($version !== null) {
            $version = Release::validate($version);
        }
        $docker = new Docker();
        if (!$input->getOption('no-start')) {
            $docker->requireAvailable();
        }
        $port = Project::port($input->getOption('port'));
        $directory = Project::target($input->getArgument('directory'));
        $http = new HttpClient();
        $version ??= Release::latest($http);
        $io->text('Downloading official MODX '.$version.' package…');
        $archive = tempnam(sys_get_temp_dir(), 'modx-download-');
        if ($archive === false) {
            throw new \RuntimeException('Cannot create temporary download file.');
        }
        try {
            $http->download(Release::url($version), $archive);
            if ($checksum !== null && !hash_equals(strtolower($checksum), hash_file('sha256', $archive))) {
                throw new \RuntimeException('Archive SHA-256 mismatch. Project files have not been written.');
            }
            $project = (new Scaffolder())->create($directory, $version, $port, $archive, $email, $language);
        } finally {
            @unlink($archive);
        }
        $io->success('Project prepared: '.$directory);
        if ($input->getOption('no-start')) {
            $io->text('Run modx up from the project directory to install MODX. Private credentials are in .env.');
            return Command::SUCCESS;
        }
        $io->text('Building containers and installing MODX. The first build can take several minutes.');
        $docker->up($project, $output);
        self::showAccess($project, $io, true);
        return Command::SUCCESS;
    }

    public static function showAccess(Project $project, SymfonyStyle $io, bool $credentials): void
    {
        $env = $project->env();
        $url = 'http://localhost:'.$env['HTTP_PORT'];
        $io->success('MODX is ready.');
        $io->definitionList(['Site' => $url.'/'], ['Manager' => $url.'/manager/']);
        if ($credentials) {
            $io->definitionList(['Username' => $env['ADMIN_USER']], ['Initial password' => $env['ADMIN_PASSWORD']]);
            $io->note('Initial credentials are saved in the private .env file. Changing .env does not rotate installed passwords.');
        }
    }
}
