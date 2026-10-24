<?php

declare(strict_types=1);

namespace Pulse\Logs;

final class LogCollector
{
    public const MAX_BUFFERED = 1000;

    /** @var list<array{at: string, level: string, context?: string, message: string}> */
    private array $buffer = [];

    public function __construct(private readonly string $minimum = 'DEBUG')
    {
    }

    public function size(): int
    {
        return count($this->buffer);
    }

    /** @param array{at: string, level: string, context?: string, message: string} $entry */
    public function push(array $entry): void
    {
        if (!LogLevel::atLeast($entry['level'], $this->minimum)) {
            return;
        }
        $this->buffer[] = $entry;
        if (count($this->buffer) > self::MAX_BUFFERED) {
            $this->buffer = array_slice($this->buffer, -self::MAX_BUFFERED);
        }
    }

    /** @return list<array{at: string, level: string, context?: string, message: string}> */
    public function take(int $max): array
    {
        $taken = array_slice($this->buffer, 0, $max);
        $this->buffer = array_slice($this->buffer, $max);

        return $taken;
    }

    /** @param list<array{at: string, level: string, context?: string, message: string}> $entries */
    public function putBack(array $entries): void
    {
        $this->buffer = array_slice([...$entries, ...$this->buffer], -self::MAX_BUFFERED);
    }

    public function clear(): void
    {
        $this->buffer = [];
    }
}
