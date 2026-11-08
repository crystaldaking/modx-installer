<?php

declare(strict_types=1);

namespace ModxInstaller;

use Symfony\Component\Filesystem\Filesystem;

final class Scaffolder
{
    public function create(string $directory, string $version, int $port, string $archive, string $email, string $language): Project
    {
        $fs = new Filesystem();
        // Stage all files before touching the target. Keep an existing empty directory on failure.
        $stage = sys_get_temp_dir().'/modx-stage-'.bin2hex(random_bytes(8));
        $fs->mkdir($stage, 0700);
        try {
            (new Archive())->extract($archive, $stage.'/app', $version);
            $resources = dirname(__DIR__).'/resources';
            foreach (['compose.yaml' => 'compose.yaml', 'Dockerfile' => 'docker/php/Dockerfile', 'nginx.conf' => 'docker/nginx/default.conf', 'install.php' => 'docker/install.php', 'verify.php' => 'docker/verify.php'] as $from => $to) {
                $fs->copy($resources.'/'.$from, $stage.'/'.$to);
            }
            $name = strtolower(preg_replace('/[^a-z0-9]+/i', '-', basename($directory)));
            $metadata = ['schema' => 1, 'name' => 'modx-'.trim($name, '-').'-'.substr(hash('sha256', $directory), 0, 8), 'modx_version' => $version, 'download_url' => Release::url($version), 'archive_sha256' => hash_file('sha256', $archive), 'installer_version' => Application::VERSION];
            $fs->dumpFile($stage.'/modx.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $vars = ['HTTP_PORT' => (string)$port, 'DB_NAME' => 'modx', 'DB_USER' => 'modx', 'DB_PASSWORD' => bin2hex(random_bytes(24)), 'DB_ROOT_PASSWORD' => bin2hex(random_bytes(24)), 'ADMIN_USER' => 'admin', 'ADMIN_PASSWORD' => bin2hex(random_bytes(16)), 'ADMIN_EMAIL' => $email, 'MODX_LANGUAGE' => $language, 'LOCAL_UID' => (string)(function_exists('posix_getuid') ? posix_getuid() : 1000), 'LOCAL_GID' => (string)(function_exists('posix_getgid') ? posix_getgid() : 1000)];
            $env = "# Local development credentials. Do not commit this file.\n";
            $example = "# Copy to .env only for a NEW installation; set fresh random passwords.\n";
            foreach ($vars as $key => $value) {
                $env .= $key.'='.$value."\n";
                $example .= $key.'='.(str_contains($key, 'PASSWORD') ? '' : $value)."\n";
            }
            $fs->dumpFile($stage.'/.env', $env);
            $fs->chmod($stage.'/.env', 0600);
            $fs->dumpFile($stage.'/.env.example', $example);
            $fs->dumpFile($stage.'/.gitignore', "/.env\n/app/core/config/config.inc.php\n/app/config.core.php\n/app/manager/config.core.php\n/app/connectors/config.core.php\n/app/core/cache/*\n/app/core/packages/*\n/.modx-installed\n/.DS_Store\n");
            $fs->dumpFile($stage.'/README.md', "# ".basename($directory)."\n\nLocal MODX ".$version." site created with [MODX Installer](https://github.com/crystaldaking/modx-installer).\n\nRun `modx up` to start and complete installation. Site: http://localhost:".$port."/; manager: http://localhost:".$port."/manager/.\n\nInitial credentials are in the private `.env` file. `modx down` preserves the database volume. Use `modx logs` for diagnostics. This environment is for local development.\n\nThe `app/` folder is bind mounted; edits are immediately visible. Database contents live in Docker, not Git. Back up the database separately. Changing `.env` passwords after installation does not rotate existing database or manager passwords.\n");
            if (count(scandir($directory)) > 2) {
                throw new \RuntimeException('Target directory changed during preparation; refusing to overwrite it.');
            }
            foreach (scandir($stage) as $file) {
                if ($file !== '.' && $file !== '..') {
                    $fs->rename($stage.'/'.$file, $directory.'/'.$file);
                }
            }
            return new Project($directory, $metadata);
        } finally {
            $fs->remove($stage);
        }
    }
}
