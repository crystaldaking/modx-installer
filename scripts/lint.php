<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root.'/bin/modx'];
foreach (['src', 'resources', 'scripts', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') { $files[] = $file->getPathname(); }
    }
}
foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file);
    exec($command, $lines, $status);
    if ($status !== 0) { fwrite(STDERR, implode("\n", $lines)."\n"); exit($status); }
    $lines = [];
}
echo count($files)." PHP files passed syntax checks.\n";
