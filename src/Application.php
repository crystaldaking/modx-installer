<?php

declare(strict_types=1);

namespace ModxInstaller;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends \Symfony\Component\Console\Application
{
    public const VERSION = '0.2.1';

    public function __construct()
    {
        parent::__construct('MODX Installer', self::VERSION);
        $this->addCommands([new Command\NewCommand(), new Command\LifecycleCommand('up'), new Command\LifecycleCommand('down'), new Command\LifecycleCommand('logs'), new Command\DoctorCommand()]);
    }

    public static function normalizeArguments(array $argv): array
    {
        // Symfony reserves --version globally. In `new`, accept the original proposed spelling.
        $command = null;
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '-')) {
                $command = $arg;
                break;
            }
        }
        if ($command === 'new') {
            foreach ($argv as $i => $arg) {
                if ($arg === '--') {
                    break;
                }
                if (str_starts_with($arg, '--version=')) {
                    $argv[$i] = '--modx-version='.substr($arg, 10);
                } elseif ($arg === '--version' && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $argv[$i] = '--modx-version';
                }
            }
        }
        return $argv;
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return parent::run($input ?? new ArgvInput(self::normalizeArguments($_SERVER['argv'])), $output);
    }
}
