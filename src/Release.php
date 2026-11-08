<?php

declare(strict_types=1);

namespace ModxInstaller;

final class Release
{
    public static function validate(string $version): string
    {
        $version = ltrim($version, 'v');
        if (!preg_match('/^3\.\d+\.\d+-pl$/D', $version)) {
            throw new \InvalidArgumentException('Use a stable MODX 3 version, for example 3.2.4-pl.');
        }
        return $version;
    }

    public static function latestFromTags(array $tags): string
    {
        $versions = [];
        foreach ($tags as $tag) {
            if (isset($tag['name']) && preg_match('/^v?(3\.\d+\.\d+-pl)$/D', $tag['name'], $match)) {
                $versions[] = $match[1];
            }
        }
        if ($versions === []) {
            throw new \RuntimeException('No stable MODX 3 tag found. Specify --modx-version explicitly.');
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        return $versions[0];
    }

    public static function latest(HttpClient $http): string
    {
        try {
            return self::latestFromTags(json_decode($http->get('https://api.github.com/repos/modxcms/revolution/tags?per_page=100'), true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot resolve latest MODX: '.$e->getMessage().' Retry with --modx-version=3.x.y-pl.', 0, $e);
        }
    }

    public static function url(string $version): string
    {
        return 'https://modx.com/download/direct/modx-'.self::validate($version).'.zip';
    }
}
