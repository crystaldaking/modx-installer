<?php

declare(strict_types=1);

// MODX bootstrap can call exit(0) on failure. Only reaching the end proves success.
$verified = false;
register_shutdown_function(static function () use (&$verified): void {
    if (!$verified) {
        fwrite(STDERR, "MODX verification did not complete. Project files and database have been preserved.\n");
        exit(1);
    }
});

if (in_array('--http', $argv, true)) {
    $deadline = microtime(true) + 30;
    do {
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Accept-Language: en\r\n"]]);
        $response = @fopen('http://web/manager/', 'rb', false, $context);
        if (is_resource($response)) {
            $headers = stream_get_meta_data($response)['wrapper_data'] ?? [];
            fclose($response);
            if (preg_match('~^HTTP/\S+ 200(?: |$)~', $headers[0] ?? '')) {
                $verified = true;
                fwrite(STDOUT, "MODX HTTP endpoint is ready.\n");
                exit(0);
            }
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    fwrite(STDERR, "Manager HTTP endpoint is not ready. Inspect modx logs.\n");
    exit(1);
}

$lock = fopen('/tmp/modx-installer-operation.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another installer process is still running in this container.\n");
    exit(1);
}
$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$installed = in_array('--installed', $argv, true);
if (!is_file('/var/www/html/core/config/config.inc.php')) {
    fwrite(STDERR, "MODX configuration is missing. Restore it from backup; do not reinstall over existing data.\n");
    exit(1);
}
try {
    require '/var/www/html/config.core.php';
    require MODX_CORE_PATH.'config/config.inc.php';
    // Check the actual DB before MODX can answer from its filesystem cache.
    $pdo = new PDO($database_dsn, $database_user, $database_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table_prefix)) {
        throw new RuntimeException('Invalid table prefix.');
    }
    $contexts = $pdo->query('SELECT COUNT(*) FROM `'.$table_prefix.'context` WHERE `key` IN (\'web\', \'mgr\')')->fetchColumn();
    $start = $pdo->query('SELECT `value` FROM `'.$table_prefix.'system_settings` WHERE `key` = \'site_start\'')->fetchColumn();
    if ((int)$contexts !== 2 || $start === false) {
        throw new RuntimeException('Required MODX records are missing.');
    }
    require MODX_CORE_PATH.'vendor/autoload.php';
    $modx = new MODX\Revolution\modX();
    if (!$modx->initialize('mgr')) {
        throw new RuntimeException('MODX manager bootstrap failed.');
    }
    if (!$installed) {
        $user = $modx->getObject(MODX\Revolution\modUser::class, ['username' => $config['ADMIN_USER'], 'active' => 1]);
        if (!$user || !$user->passwordMatches($config['ADMIN_PASSWORD'])) {
            fwrite(STDERR, "MODX administrator verification failed. Setup files have been preserved.\n");
            exit(1);
        }
        // MODX's one-time welcome screen calls reloadConfig() on first login.
        // That resets cultureKey to the site's language after the manager has
        // loaded a different language, leaving raw lexicon keys in menu caches.
        // Disable only this onboarding popup on new installs, not the dashboard.
        $welcome = $modx->getObject(MODX\Revolution\modSystemSetting::class, 'welcome_screen');
        if ($welcome) {
            if ((bool)$welcome->get('value')) {
                $welcome->set('value', false);
                if (!$welcome->save()) {
                    throw new RuntimeException('Cannot disable the initial welcome screen.');
                }
            }
            $cacheResults = [];
            $modx->getCacheManager()->refresh(['system_settings' => []], $cacheResults);
            if (($cacheResults['system_settings'] ?? false) !== true) {
                throw new RuntimeException('Cannot refresh initial system settings.');
            }
        }
    }
    if (!chmod(MODX_CORE_PATH.'config/config.inc.php', 0600)) {
        throw new RuntimeException('Cannot protect database configuration permissions.');
    }
    // Remove setup only after first-install administrator verification.
    $setup = '/var/www/html/setup';
    if (!$installed && is_link($setup)) {
        throw new RuntimeException('Setup directory must not be a symbolic link.');
    }
    if (!$installed && is_dir($setup)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($setup, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $ok = $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (!$ok) {
                throw new RuntimeException('Cannot remove setup files.');
            }
        }
        if (!rmdir($setup)) {
            throw new RuntimeException('Cannot remove setup directory.');
        }
    }
} catch (Throwable) {
    // PDO/parse errors can contain connection details. Never print their arguments.
    fwrite(STDERR, "MODX database/bootstrap verification failed. Check configuration, database contents and file permissions locally.\n");
    exit(1);
}
$verified = true;
fwrite(STDOUT, $installed ? "MODX installation verified.\n" : "MODX administrator verified; setup removed.\n");
