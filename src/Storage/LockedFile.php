<?php

declare(strict_types=1);

namespace Pulse\Storage;

final class LockedFile
{
    public static function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory);
    }

    /**
     * @template T
     * @param callable(string): array{0: ?string, 1: T} $change
     * @return T|null
     */
    public static function update(string $path, callable $change): mixed
    {
        if (!self::ensureDirectory(dirname($path))) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $content = (string) stream_get_contents($handle);
            [$next, $result] = $change($content);
            if ($next !== null) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $next);
                fflush($handle);
            }
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    public static function append(string $path, string $line, int $maxBytes, callable $trim): void
    {
        if (!self::ensureDirectory(dirname($path))) {
            return;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $line);
            if ((int) ftell($handle) > $maxBytes) {
                rewind($handle);
                $content = $trim((string) stream_get_contents($handle));
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $content);
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public static function read(string $path): ?string
    {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    public static function replace(string $path, string $content): void
    {
        if (!self::ensureDirectory(dirname($path))) {
            return;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $content) !== false) {
            @rename($temporary, $path);
        }
    }
}
