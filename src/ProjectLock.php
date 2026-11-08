<?php

declare(strict_types=1);

namespace ModxInstaller;

final class ProjectLock
{
    private $handle;

    public function __construct(string $directory)
    {
        $path = $directory.'/.modx.lock';
        if (is_link($path)) {
            throw new \RuntimeException('Project lock must not be a symbolic link.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open project operation lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another MODX operation is running for this project. Retry after it finishes.');
        }
        $this->handle = $handle;
    }

    public function __destruct()
    {
        // Keep the file: unlinking a locked inode would allow a second independent lock.
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }
}
