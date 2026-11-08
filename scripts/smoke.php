<?php

// Real distribution + real Docker. Only the unique temporary project's resources are deleted.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use ModxInstaller\{Application, Project};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/modx-smoke-'.bin2hex(random_bytes(6));
$binary = $argv[1] ?? $root.'/bin/modx';
$run = static function (array $args) use ($binary): void {
    $process = new Process([PHP_BINARY, $binary, ...$args]);
    $process->setTimeout(1200);
    $process->mustRun(static function (string $type, string $buffer): void {
        // Do not publish the generated manager password in CI logs.
        if (!str_contains($buffer, 'Initial password')) { echo $buffer; }
    });
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
    $run(['new', $directory, '--no-start', '--no-interaction']);
    $project = Project::find($directory);
    $run(['doctor', '--project-dir='.$directory]);
    $run(['up', '--project-dir='.$directory, '--no-interaction']);
    $env = $project->env();
    $url = 'http://127.0.0.1:'.$env['HTTP_PORT'];
    [$status, $body] = $get($url.'/');
    $assert($status === 200 && is_string($body) && strlen($body) > 50, 'Homepage is not working.');
    [$status, $body] = $get($url.'/manager/');
    $assert($status === 200 && str_contains(strtolower((string)$body), 'login'), 'Manager login page is not working.');
    foreach (['/core/docs/version.inc.php', '/setup/', '/.env', '/config.core.php', '/manager/config.core.php'] as $path) {
        [$status] = $get($url.$path);
        $assert($status === 404, 'Sensitive path accessible: '.$path);
    }
    $assert(!is_dir($directory.'/app/setup'), 'Setup directory still exists.');
    $assert(is_file($directory.'/.modx-installed'), 'Installation marker missing.');
    $assert((fileperms($directory.'/.env') & 0777) === 0600, 'Environment permissions are not private.');
    $configHash = hash_file('sha256', $directory.'/app/core/config/config.inc.php');
    $run(['logs', '--project-dir='.$directory, '--tail=5', '--service=web']);
    $run(['down', '--project-dir='.$directory]);
    $run(['up', '--project-dir='.$directory]);
    $assert(hash_file('sha256', $directory.'/app/core/config/config.inc.php') === $configHash, 'Existing config was changed.');
    [$status] = $get($url.'/manager/');
    $assert($status === 200, 'Manager/database did not survive down/up.');
    // Check credentials against the persisted database, even after the normal startup skipped installation.
    $verify = new Process(['docker', 'compose', '--project-directory', $directory, '--env-file', $directory.'/.env', '-f', $directory.'/compose.yaml', '-p', $project->metadata['name'], 'exec', '-T', 'php', 'php', '/opt/modx/verify.php'], $directory, $env, json_encode($env, JSON_THROW_ON_ERROR), 60);
    $verify->mustRun();
    echo 'PASS: real MODX '.$project->metadata['modx_version'].' install, HTTP paths, cleanup and persistence (installer '.Application::VERSION.").\n";
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
