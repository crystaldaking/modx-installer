<?php

declare(strict_types=1);

namespace ModxInstaller;

use Symfony\Component\Filesystem\Filesystem;

final class Archive
{
    public function extract(string $archive, string $destination, string $version): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('The download is not a readable ZIP archive.');
        }
        $prefix = 'modx-'.Release::validate($version).'/';
        $total = 0;
        $entries = [];
        $paths = [];
        try {
            if (is_link($destination) || (file_exists($destination) && (!is_dir($destination) || count(scandir($destination)) > 2))) {
                throw new \RuntimeException('Archive destination must be a new or empty directory.');
            }
            if ($zip->numFiles > 40000) {
                throw new \RuntimeException('Archive contains too many files.');
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                if (!str_starts_with($name, $prefix)
                    || str_contains($name, '\\') || str_contains($name, "\0")
                    || preg_match('~(^|/)\.\.(/|$)~', $name)
                    || (($attributes >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException('Unsafe ZIP entry: '.$name);
                }
                $total += $stat['size'];
                if ($total > 600 * 1024 * 1024) {
                    throw new \RuntimeException('Uncompressed archive exceeds 600 MiB.');
                }
                $relative = implode('/', array_filter(explode('/', substr($name, strlen($prefix))), static fn (string $part): bool => $part !== '' && $part !== '.'));
                if ($relative === '') {
                    continue;
                }
                $directory = str_ends_with($name, '/');
                $key = strtolower($relative);
                if (isset($paths[$key]) && (!$directory || !$paths[$key])) {
                    throw new \RuntimeException('Conflicting ZIP entry: '.$relative);
                }
                $paths[$key] = $directory;
                $entries[] = ['name' => $name, 'relative' => $relative, 'directory' => $directory, 'size' => $stat['size']];
            }
            foreach (array_keys($paths) as $path) {
                for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($paths[$parent]) && !$paths[$parent]) {
                        throw new \RuntimeException('ZIP file is also used as a directory: '.$parent);
                    }
                }
            }
            foreach (['index.php', 'setup/index.php', 'core/docs/version.inc.php'] as $required) {
                if ($zip->locateName($prefix.$required) === false) {
                    throw new \RuntimeException('Official traditional MODX package is missing '.$required.'.');
                }
            }
            $fs = new Filesystem();
            $fs->mkdir($destination);
            foreach ($entries as $entry) {
                ['name' => $name, 'relative' => $relative, 'directory' => $directory, 'size' => $size] = $entry;
                $target = $destination.'/'.$relative;
                if ($directory) {
                    $fs->mkdir($target);
                    continue;
                }
                $fs->mkdir(dirname($target));
                $source = $zip->getStream($name);
                if ($source === false) {
                    throw new \RuntimeException('Cannot read ZIP entry: '.$relative.'.');
                }
                $out = fopen($target, 'xb');
                if ($out === false) {
                    fclose($source);
                    throw new \RuntimeException('Cannot extract '.$relative.'.');
                }
                try {
                    if (stream_copy_to_stream($source, $out, $size + 1) !== $size) {
                        throw new \RuntimeException('Incomplete ZIP entry: '.$relative);
                    }
                } finally {
                    fclose($source);
                    fclose($out);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
