<?php

declare(strict_types=1);

namespace ModxInstaller;

/** Literal, single-line environment values: no shell or variable evaluation. */
final class Environment
{
    public static function parse(#[\SensitiveParameter] string $contents): array
    {
        if (strlen($contents) > 65536 || str_contains($contents, "\0")) {
            throw new \RuntimeException('Invalid project .env: maximum size is 64 KiB and NUL bytes are not allowed.');
        }
        $values = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*(.*)$/D', $line, $match)) {
                self::invalid($number + 1);
            }
            $value = $match[2];
            if ($value !== '' && ($value[0] === "'" || $value[0] === '"')) {
                $quote = $value[0];
                $decoded = '';
                $closed = false;
                for ($i = 1, $length = strlen($value); $i < $length; ++$i) {
                    $char = $value[$i];
                    if ($char === $quote) {
                        $rest = ltrim(substr($value, $i + 1));
                        if ($rest !== '' && !str_starts_with($rest, '#')) {
                            self::invalid($number + 1);
                        }
                        $closed = true;
                        break;
                    }
                    if ($char === '\\' && isset($value[$i + 1])) {
                        $next = $value[$i + 1];
                        $escapes = $quote === '"' ? ['n' => "\n", 'r' => "\r", 't' => "\t", '\\' => '\\', '"' => '"', '$' => '$'] : ["'" => "'"];
                        if (isset($escapes[$next])) {
                            $decoded .= $escapes[$next];
                            ++$i;
                            continue;
                        }
                    }
                    $decoded .= $char;
                }
                if (!$closed) {
                    self::invalid($number + 1);
                }
                $value = $decoded;
            } else {
                $value = rtrim(preg_replace('/[ \t]+#.*$/', '', $value));
                if (preg_match('/[\s\'"`]/', $value)) {
                    self::invalid($number + 1);
                }
            }
            $values[$match[1]] = $value;
        }
        return $values;
    }

    private static function invalid(int $line): never
    {
        throw new \RuntimeException('Invalid syntax in project .env at line '.$line.'. Use KEY=value or a single-line quoted value; values are hidden to protect credentials.');
    }
}
