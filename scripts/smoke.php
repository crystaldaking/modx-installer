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
        $run(['new', $directory, '--no-start', '--no-interaction']);
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
    $assert(!is_dir($directory.'/app/setup'), 'Setup directory still exists.');
    $assert(is_file($directory.'/.modx-installed'), 'Installation marker missing.');
    $assert((fileperms($directory.'/.env') & 0777) === 0600, 'Environment permissions are not private.');
    $assert((fileperms($directory.'/app/core/config/config.inc.php') & 0777) === 0600, 'Installed DB configuration is not private.');
    $configHash = hash_file('sha256', $directory.'/app/core/config/config.inc.php');
    $run(['logs', '--project-dir='.$directory, '--tail=5', '--service=web']);
    $run(['down', '--project-dir='.$directory]);
    $run(['up', '--project-dir='.$directory]);
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
    echo 'PASS: MODX '.$project->metadata['modx_version'].' install, readiness, persistence, legacy projects, missing config/DB and exit(0) detection (installer '.Application::VERSION.").\n";

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
