<?php

declare(strict_types=1);

namespace ModxInstaller;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

final class Project
{
    public const MARKER = 'modx.json';

    public function __construct(public readonly string $directory, public readonly array $metadata) {}

    public static function find(string $directory): self
    {
        $directory = realpath($directory) ?: throw new \RuntimeException('Project directory does not exist.');
        while (true) {
            if (is_file($directory.'/'.self::MARKER)) {
                $data = json_decode(file_get_contents($directory.'/'.self::MARKER), true, 512, JSON_THROW_ON_ERROR);
                if (($data['schema'] ?? null) !== 1 || !preg_match('/^modx-[a-z0-9-]+$/D', $data['name'] ?? '')) {
                    throw new \RuntimeException('Invalid or unsupported modx.json.');
                }
                return new self($directory, $data);
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('No MODX project found. Run modx new <directory> first.');
            }
            $directory = $parent;
        }
    }

    public static function target(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Provide a target directory.');
        }
        $fs = new Filesystem();
        $absolute = \Symfony\Component\Filesystem\Path::makeAbsolute($path, getcwd());
        if (is_link($absolute) || (file_exists($absolute) && !is_dir($absolute))) {
            throw new \RuntimeException('Target must be an empty directory, not a file or symbolic link.');
        }
        if (is_dir($absolute) && count(scandir($absolute)) > 2) {
            throw new \RuntimeException('Target directory is not empty. Existing files will never be overwritten.');
        }
        $name = basename($absolute);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,62}$/D', $name)) {
            throw new \InvalidArgumentException('Directory name must start with a letter or digit and contain up to 63 letters, digits, dots, underscores or hyphens.');
        }
        $fs->mkdir($absolute);
        return realpath($absolute);
    }

    public static function port(?string $requested): int
    {
        if ($requested !== null && (!ctype_digit($requested) || (int)$requested < 1024 || (int)$requested > 65535)) {
            throw new \InvalidArgumentException('Port must be an integer between 1024 and 65535.');
        }
        $first = $requested === null ? 8080 : (int)$requested;
        $last = $requested === null ? 8180 : $first;
        for ($port = $first; $port <= $last; ++$port) {
            $socket = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);
            if ($socket !== false) {
                fclose($socket);
                return $port;
            }
        }
        throw new \RuntimeException('No available HTTP port. Choose another --port.');
    }

    public function env(): array
    {
        $path = $this->directory.'/.env';
        if (!is_file($path)) {
            throw new \RuntimeException('Project .env is missing. Restore it from your private backup; .env.example contains no usable passwords.');
        }
        $vars = (new Dotenv())->parse(file_get_contents($path), $path);
        foreach (['HTTP_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'ADMIN_USER', 'ADMIN_PASSWORD', 'ADMIN_EMAIL', 'LOCAL_UID', 'LOCAL_GID'] as $key) {
            if (($vars[$key] ?? '') === '') {
                throw new \RuntimeException('Missing '.$key.' in project .env.');
            }
        }
        if (!ctype_digit($vars['HTTP_PORT']) || (int)$vars['HTTP_PORT'] < 1024 || (int)$vars['HTTP_PORT'] > 65535) {
            throw new \RuntimeException('Invalid HTTP_PORT in .env.');
        }
        return $vars;
    }
}
