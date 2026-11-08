<?php

declare(strict_types=1);

namespace ModxInstaller;

final class HttpClient
{
    public function get(string $url): string
    {
        $body = '';
        $this->request($url, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
            if (strlen($body) > 5 * 1024 * 1024) {
                throw new \RuntimeException('Metadata response is too large.');
            }
        });
        return $body;
    }

    public function download(string $url, string $path): void
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create download file.');
        }
        $size = 0;
        try {
            $this->request($url, static function (string $chunk) use ($stream, &$size): void {
                $size += strlen($chunk);
                if ($size > 150 * 1024 * 1024 || fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Download exceeded 150 MiB or could not be written.');
                }
            }, 600);
        } finally {
            fclose($stream);
        }
    }

    private function request(string $url, callable $consume, int $timeout = 180): void
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'MODX-Installer/'.Application::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($consume): int {
                $consume($chunk);
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false) {
                throw new \RuntimeException('HTTPS request failed: '.curl_error($curl).' ('.$url.').');
            }
        } finally {
            // PHP 8.5 frees CurlHandle automatically; unset also supports PHP 8.2.
            unset($curl);
        }
    }
}
