<?php

declare(strict_types=1);

$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_file('/var/www/html/core/config/config.inc.php')) {
    fwrite(STDERR, "MODX config was not created. Inspect modx logs and app/core/cache/logs.\n");
    exit(1);
}
require '/var/www/html/config.core.php';
require MODX_CORE_PATH.'vendor/autoload.php';
$modx = new MODX\Revolution\modX();
$modx->initialize('mgr');
$user = $modx->getObject(MODX\Revolution\modUser::class, ['username' => $config['ADMIN_USER'], 'active' => 1]);
if (!$user || !$user->passwordMatches($config['ADMIN_PASSWORD'])) {
    fwrite(STDERR, "MODX administrator verification failed. Setup files have been preserved for diagnosis.\n");
    exit(1);
}
fwrite(STDOUT, "MODX administrator verified.\n");
// Only remove setup after a successful bootstrap and password check.
$setup = '/var/www/html/setup';
if (is_dir($setup)) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($setup, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $ok = $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        if (!$ok) {
            throw new RuntimeException('Cannot remove setup files. Fix permissions and rerun modx up.');
        }
    }
    if (!rmdir($setup)) {
        throw new RuntimeException('Cannot remove setup directory.');
    }
}
