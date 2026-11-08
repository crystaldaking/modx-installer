<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

$projectRoot = dirname(__DIR__);
$installer = realpath($argv[1] ?? $projectRoot.'/install.sh') ?: throw new RuntimeException('Installer script not found.');
$testRoot = sys_get_temp_dir().'/modx-native-bootstrap-'.bin2hex(random_bytes(6));
$testUserHome = $testRoot.'/user home\'s $(literal)';
$fs = new Filesystem();
$fs->mkdir($testUserHome, 0700);
$finder = new ExecutableFinder();
$version = null;
try {
    foreach (['bash', 'zsh', 'fish'] as $name) {
        $shell = $finder->find($name);
        if ($shell === null) { continue; }
        echo 'Testing native bootstrap and PATH in '.$name."\n";
        // Only child processes receive the isolated HOME; host profiles are never touched.
        $environment = ['HOME' => $testUserHome, 'SHELL' => $shell, 'ZDOTDIR' => false, 'XDG_CONFIG_HOME' => false, 'PATH' => dirname(PHP_BINARY).':/usr/local/bin:/usr/bin:/bin'];
        $arguments = ['/bin/sh', $installer];
        if ($version !== null) { $arguments[] = '--version='.$version; }
        $process = new Process($arguments, $testRoot, $environment);
        $process->setTimeout(420);
        $process->mustRun(static function (string $type, string $buffer): void { echo $buffer; });
        $metadata = json_decode(file_get_contents($testUserHome.'/.local/bin/.modx-installer.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = $metadata['version'];
        $probe = new Process([$shell, '-ic', 'cd /; command -v modx; modx --version'], '/', $environment);
        $probe->setTimeout(30);
        $probe->mustRun();
        if (!str_contains($probe->getOutput(), $testUserHome.'/.local/bin/modx') || !str_contains($probe->getOutput(), 'MODX Installer '.substr($version, 1))) {
            throw new RuntimeException($name.' did not discover the installed command from another directory.');
        }
        echo 'PASS: '.$name.' resolves modx '.substr($version, 1)." from /.\n";
    }
    if ($version === null) { throw new RuntimeException('No supported test shell was found.'); }
} finally {
    $fs->remove($testRoot);
}
