<?php

declare(strict_types=1);

namespace Pulse\Logs;

final class LogLevel
{
    public const LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'];

    private const FRAMEWORK_LEVELS = [
        'TRACE' => 'DEBUG',
        'DEBUG' => 'DEBUG',
        'INFO' => 'INFO',
        'NOTICE' => 'INFO',
        'WARN' => 'WARN',
        'WARNING' => 'WARN',
        'ERROR' => 'ERROR',
        'CRITICAL' => 'FATAL',
        'ALERT' => 'FATAL',
        'EMERGENCY' => 'FATAL',
        'FATAL' => 'FATAL',
        'PANIC' => 'FATAL',
    ];

    public static function normalize(?string $raw): string
    {
        $upper = strtoupper(trim((string) $raw));

        return in_array($upper, self::LEVELS, true) ? $upper : 'DEBUG';
    }

    public static function fromFramework(string $name): string
    {
        return self::FRAMEWORK_LEVELS[strtoupper($name)] ?? 'INFO';
    }

    public static function atLeast(string $level, string $minimum): bool
    {
        return (int) array_search($level, self::LEVELS, true) >= (int) array_search($minimum, self::LEVELS, true);
    }
}
