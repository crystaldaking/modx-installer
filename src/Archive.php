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
        try {
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
            }
            foreach (['index.php', 'setup/index.php', 'core/docs/version.inc.php'] as $required) {
                if ($zip->locateName($prefix.$required) === false) {
                    throw new \RuntimeException('Official traditional MODX package is missing '.$required.'.');
                }
            }
            $fs = new Filesystem();
            $fs->mkdir($destination);
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                $relative = preg_replace('~(^|/)\./~', '$1', substr($name, strlen($prefix)));
                if ($relative === '') {
                    continue;
                }
                $target = $destination.'/'.$relative;
                if (str_ends_with($name, '/')) {
                    $fs->mkdir($target);
                    continue;
                }
                $fs->mkdir(dirname($target));
                $source = $zip->getStream($name);
                $out = fopen($target, 'xb');
                if ($source === false || $out === false) {
                    throw new \RuntimeException('Cannot extract '.$relative.'.');
                }
                try {
                    if (stream_copy_to_stream($source, $out) !== $zip->statIndex($i)['size']) {
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
