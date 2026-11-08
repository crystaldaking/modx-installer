<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

if (ini_get('phar.readonly')) {
    throw new RuntimeException('Use composer build, or php -d phar.readonly=0 scripts/build-phar.php.');
}
$root = dirname(__DIR__);
require __DIR__.'/build-installer.php';
$fs = new Filesystem();
$stage = $root.'/dist/build';
$fs->remove($stage);
$fs->mkdir($stage);
try {
    foreach (['bin', 'src', 'resources'] as $directory) {
        $fs->mirror($root.'/'.$directory, $stage.'/'.$directory);
    }
    foreach (['composer.json', 'composer.lock', 'LICENSE', 'NOTICE'] as $file) {
        $fs->copy($root.'/'.$file, $stage.'/'.$file);
    }
    $process = new Process(['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-scripts', '--classmap-authoritative'], $stage);
    $process->setTimeout(180);
    $process->mustRun(static function ($type, $buffer): void { echo $buffer; });
    $path = $root.'/dist/modx.phar';
    $fs->remove($path);
    $phar = new Phar($path, 0, 'modx.phar');
    $phar->startBuffering();
    $phar->buildFromDirectory($stage);
    $phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('modx.phar'); require 'phar://modx.phar/bin/modx'; __HALT_COMPILER();\n");
    $phar->setSignatureAlgorithm(Phar::SHA512);
    $phar->stopBuffering();
    unset($phar);
    chmod($path, 0755);
    $fs->copy($root.'/install.sh', $root.'/dist/install.sh', true);
    $fs->chmod($root.'/dist/install.sh', 0755);
    file_put_contents($root.'/dist/SHA256SUMS', hash_file('sha256', $path)."  modx.phar\n".hash_file('sha256', $root.'/dist/install.sh')."  install.sh\n");
    echo "Built dist/modx.phar, dist/install.sh and dist/SHA256SUMS\n";
} finally {
    $fs->remove($stage);
}
