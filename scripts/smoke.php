<?php

// Real distribution + real Docker. Only the unique temporary project's resources are deleted.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

use ModxInstaller\{Application, Project};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/modx-smoke-'.bin2hex(random_bytes(6));
$portSocket = stream_socket_server('tcp://127.0.0.1:0', $portError, $portMessage);
if ($portSocket === false) { throw new RuntimeException('Cannot allocate an isolated smoke-test port.'); }
$testPort = substr(strrchr(stream_socket_get_name($portSocket, false), ':'), 1);
fclose($portSocket);
$binary = $argv[1] ?? $root.'/bin/modx';
$run = static function (array $args, bool $expectSuccess = true) use ($binary): void {
    echo ($expectSuccess ? 'Running' : 'Expecting failure from').' modx '.$args[0]."\n";
    // Quiet mode prevents credential output before it reaches a pipe. Filtering
    // arbitrary process chunks can leak a password split across two reads.
    $process = new Process([PHP_BINARY, $binary, ...$args, '--quiet']);
    $process->setTimeout(1200);
    $process->disableOutput();
    $process->run(static function (string $type, string $buffer): void { echo $buffer; });
    if ($process->isSuccessful() !== $expectSuccess) {
        throw new RuntimeException('Unexpected exit status for modx '.$args[0].'.');
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$get = static function (string $url): array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    return [$status, $body];
};
try {
    // --no-start exercises version discovery and deferred installation, with the PHAR if supplied.
    $previousMask = umask(0077);
    try {
        $run(['new', $directory, '--no-start', '--no-interaction', '--port='.$testPort]);
    } finally {
        umask($previousMask);
    }
    $project = Project::find($directory);
    foreach (['compose.yaml', 'docker/php/Dockerfile', 'docker/nginx/default.conf', 'docker/install.php', 'docker/verify.php'] as $file) {
        $assert(is_writable($directory.'/'.$file), 'PHAR generated a read-only file: '.$file);
    }
    $run(['doctor', '--project-dir='.$directory]);
    // The host must use its patched runtime, rather than stale project copies.
    foreach (['install.php', 'verify.php'] as $runtime) {
        $assert(file_put_contents($directory.'/docker/'.$runtime, '<?php exit(97);') !== false, 'Cannot simulate stale project runtime.');
    }
    $run(['up', '--project-dir='.$directory, '--no-interaction']);
    $env = $project->env();
    $runtimeCode = 'echo json_encode(["version" => PHP_VERSION_ID, "extensions" => array_map("extension_loaded", ["pdo_mysql", "mysqli", "intl", "gd", "zip", "Zend OPcache"])]);';
    $runtime = new Process(['docker', 'compose', '--project-directory', $directory, '--env-file', $directory.'/.env', '-f', $directory.'/compose.yaml', '-p', $project->metadata['name'], 'exec', '-T', 'php', 'php', '-r', $runtimeCode], $directory, $env, null, 30);
    $runtime->mustRun();
    $runtimeInfo = json_decode($runtime->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    $assert(intdiv($runtimeInfo['version'], 100) === 805, 'Default site container must run PHP 8.5.');
    $assert(!in_array(false, $runtimeInfo['extensions'], true), 'A required PHP extension or built-in OPcache is missing.');

    $welcomeSetting = static function (bool $enable = false) use ($project, $directory, $env): bool {
        $code = <<<'CODE'
require '/var/www/html/config.core.php'; require MODX_CORE_PATH.'vendor/autoload.php';
$m = new MODX\Revolution\modX(); $m->initialize('mgr');
$s = $m->getObject(MODX\Revolution\modSystemSetting::class, 'welcome_screen');
if (!$s) { exit(1); }
if (($argv[1] ?? '') === 'enable') {
    $s->set('value', true);
    if (!$s->save() || !$m->getCacheManager()->refresh(['system_settings' => []])) { exit(1); }
}
echo json_encode((bool)$s->get('value'));
CODE;
        $process = new Process(['docker', 'compose', '--project-directory', $directory, '--env-file', $directory.'/.env', '-f', $directory.'/compose.yaml', '-p', $project->metadata['name'], 'exec', '-T', 'php', 'php', '-r', $code, '--', $enable ? 'enable' : 'read'], $directory, $env, null, 30);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    };
    $assert($welcomeSetting() === false, 'Initial welcome popup must be disabled before the first manager login.');
    $url = 'http://127.0.0.1:'.$env['HTTP_PORT'];
    [$status, $body] = $get($url.'/');
    $assert($status === 200 && is_string($body) && strlen($body) > 50, 'Homepage is not working.');
    [$status, $body] = $get($url.'/manager/');
    $assert($status === 200 && str_contains(strtolower((string)$body), 'login'), 'Manager login page is not working.');
    [$status, $body] = $get($url.'/manager/templates/default/css/login.css');
    $assert($status === 200 && strlen((string)$body) > 50, 'Nginx cannot read public assets created under umask 0077.');
    foreach (['/core/docs/version.inc.php', '/setup/', '/.env', '/config.core.php', '/manager/config.core.php'] as $path) {
        [$status] = $get($url.$path);
        $assert($status === 404, 'Sensitive path accessible: '.$path);
    }
    // First authenticated dashboard visit uses a different language from setup.
    // A successful login page alone cannot detect the welcome-screen locale reset.
    $manager = curl_init();
    curl_setopt_array($manager, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept-Language: ru-RU,ru;q=0.9,en;q=0.8']]);
    try {
        curl_setopt($manager, CURLOPT_URL, $url.'/manager/?manager_language=ru');
        $assert(curl_exec($manager) !== false, 'Cannot select manager language.');
        curl_setopt_array($manager, [CURLOPT_URL => $url.'/manager/', CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => $env['ADMIN_USER'], 'password' => $env['ADMIN_PASSWORD'], 'login_context' => 'mgr', 'login' => '1'])]);
        $assert(curl_exec($manager) !== false, 'Manager login request failed.');
        curl_setopt_array($manager, [CURLOPT_POST => false, CURLOPT_URL => $url.'/manager/']);
        $dashboard = curl_exec($manager);
        $assert(curl_getinfo($manager, CURLINFO_RESPONSE_CODE) === 200 && is_string($dashboard)
            && str_contains($dashboard, 'modx-page-welcome') && !str_contains($dashboard, 'id="modx-login-form"'), 'Authenticated dashboard did not load.');
        foreach (['Системные настройки', 'Новый ресурс', 'Новости MODX'] as $translation) {
            $assert(str_contains($dashboard, $translation), 'First Russian manager visit is missing translation: '.$translation);
        }
        foreach (['system_settings', 'action_new_resource', 'w_newsfeed'] as $key) {
            $assert(!preg_match('~>\s*'.preg_quote($key, '~').'\s*<~', $dashboard), 'Raw manager lexicon key rendered: '.$key);
        }
        // A second request must also use correctly localized cached menus.
        $dashboard = curl_exec($manager);
        $assert(is_string($dashboard) && str_contains($dashboard, 'Системные настройки'), 'Manager menu cache lost its translation.');
        curl_setopt($manager, CURLOPT_URL, $url.'/manager/?a=security/logout');
        curl_exec($manager);
    } finally { unset($manager); }
    echo "PASS: first authenticated Russian dashboard and cached menu are localized.\n";
    $assert(!is_dir($directory.'/app/setup'), 'Setup directory still exists.');
    $assert(is_file($directory.'/.modx-installed'), 'Installation marker missing.');
    $assert((fileperms($directory.'/.env') & 0777) === 0600, 'Environment permissions are not private.');
    $assert((fileperms($directory.'/app/core/config/config.inc.php') & 0777) === 0600, 'Installed DB configuration is not private.');
    $configHash = hash_file('sha256', $directory.'/app/core/config/config.inc.php');
    $run(['logs', '--project-dir='.$directory, '--tail=5', '--service=web']);
    // Existing-site startup must preserve an explicitly re-enabled user preference.
    $assert($welcomeSetting(true) === true, 'Cannot re-enable the welcome preference for preservation testing.');
    $run(['down', '--project-dir='.$directory]);
    $run(['up', '--project-dir='.$directory]);
    $assert($welcomeSetting() === true, 'Startup overwrote an existing site welcome preference.');
    $assert(hash_file('sha256', $directory.'/app/core/config/config.inc.php') === $configHash, 'Existing config was changed.');
    [$status] = $get($url.'/manager/');
    $assert($status === 200, 'Manager/database did not survive down/up.');
    // Initial credentials are not needed once installation is complete.
    $originalEnv = file_get_contents($directory.'/.env');
    file_put_contents($directory.'/.env', preg_replace('/^ADMIN_.*\R/m', '', $originalEnv));
    $run(['up', '--project-dir='.$directory]);

    // Recoverable configuration loss must not trigger a new installation.
    $configPath = $directory.'/app/core/config/config.inc.php';
    rename($configPath, $directory.'/.config-backup');
    try {
        $run(['up', '--project-dir='.$directory], false);
        $assert(!is_file($configPath), 'Missing config was silently regenerated.');
    } finally {
        rename($directory.'/.config-backup', $configPath);
    }

    // v0.1.0 Compose has no PHP/web health checks. It must still start safely.
    $run(['down', '--project-dir='.$directory]);
    $assert(copy($root.'/tests/fixtures/legacy-compose.yaml', $directory.'/compose.yaml'), 'Cannot install legacy Compose fixture.');
    $run(['up', '--project-dir='.$directory]);

    // An early exit(0) inside MODX bootstrap must become a failed CLI command.
    $autoload = $directory.'/app/core/vendor/autoload.php';
    $originalAutoload = file_get_contents($autoload);
    file_put_contents($autoload, '<?php namespace MODX\\Revolution; class modX { public function initialize($context) { exit(0); } }');
    try {
        $run(['up', '--project-dir='.$directory], false);
    } finally {
        file_put_contents($autoload, $originalAutoload);
    }

    // Simulate a lost/corrupt DB with installed files and marker still present.
    $code = '$env=json_decode(stream_get_contents(STDIN),true); $db=new PDO("mysql:host=db;dbname=modx",$env["DB_USER"],$env["DB_PASSWORD"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec("DROP TABLE modx_context");';
    $damage = new Process(['docker', 'compose', '--project-directory', $directory, '--env-file', $directory.'/.env', '-f', $directory.'/compose.yaml', '-p', $project->metadata['name'], 'exec', '-T', 'php', 'php', '-r', $code], $directory, $env, json_encode($env, JSON_THROW_ON_ERROR), 60);
    $damage->mustRun();
    $run(['up', '--project-dir='.$directory], false);
    $assert(hash_file('sha256', $configPath) === $configHash, 'Failed startup changed existing configuration.');
    echo 'PASS: MODX '.$project->metadata['modx_version'].' install, translated first login, preserved welcome preference, readiness, persistence, legacy projects, missing config/DB and exit(0) detection (installer '.Application::VERSION.").\n";

} finally {
    if (is_file($directory.'/modx.json')) {
        $project = Project::find($directory);
        $cleanup = new Process(['docker', 'compose', '--project-directory', $directory, '--env-file', $directory.'/.env', '-f', $directory.'/compose.yaml', '-p', $project->metadata['name'], 'down', '--volumes', '--rmi', 'local'], $directory, $project->env());
        $cleanup->setTimeout(120);
        $cleanup->run();
        if (!$cleanup->isSuccessful()) {
            fwrite(STDERR, "Cleanup failed; temporary project kept at ".$directory."\n");
        } else {
            (new Filesystem())->remove($directory);
        }
    } else {
        (new Filesystem())->remove($directory);
    }
}
