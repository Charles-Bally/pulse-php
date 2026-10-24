<?php

declare(strict_types=1);

namespace Pulse\Logs;

use Pulse\Sanitizer;
use Pulse\Support\Text;

final class LogEntry
{
    private const MAX_MESSAGE_CHARS = 4000;
    private const MAX_CONTEXT_CHARS = 80;

    /** @return array{at: string, level: string, context?: string, message: string} */
    public static function make(string $level, string $message, ?string $context = null, ?\DateTimeInterface $at = null): array
    {
        $entry = ['at' => Text::iso($at ?? new \DateTimeImmutable()), 'level' => LogLevel::normalize($level)];
        $context = $context === null ? '' : Text::cut(trim($context), self::MAX_CONTEXT_CHARS);
        if ($context !== '') {
            $entry['context'] = $context;
        }
        $text = Text::cut(Sanitizer::redactSecrets(trim($message)), self::MAX_MESSAGE_CHARS);
        $entry['message'] = $text === '' ? '(vide)' : $text;

        return $entry;
    }

    /** @return array{at: string, level: string, context?: string, message: string}|null */
    public static function fromArray(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['at'] ?? null) || !is_string($raw['message'] ?? null)) {
            return null;
        }
        $entry = ['at' => $raw['at'], 'level' => LogLevel::normalize(is_string($raw['level'] ?? null) ? $raw['level'] : null)];
        if (is_string($raw['context'] ?? null) && $raw['context'] !== '') {
            $entry['context'] = $raw['context'];
        }
        $entry['message'] = $raw['message'];

        return $entry;
    }
}
