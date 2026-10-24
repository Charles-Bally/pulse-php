<?php

declare(strict_types=1);

namespace Pulse\Logs;

use Pulse\Storage\LockedFile;
use Pulse\Support\Text;

final class LogSpool
{
    private const TRIM_ABOVE_BYTES = 1048576;
    private const KEEP_BYTES = 524288;

    public function __construct(private readonly string $path)
    {
    }

    public static function in(string $directory): self
    {
        return new self(rtrim($directory, '/') . '/logs.ndjson');
    }

    /** @param array{at: string, level: string, context?: string, message: string} $entry */
    public function append(array $entry): void
    {
        LockedFile::append($this->path, Text::json($entry) . "\n", self::TRIM_ABOVE_BYTES, static fn (string $content) => self::keepLast($content, self::KEEP_BYTES));
    }

    /** @return list<array{at: string, level: string, context?: string, message: string}> */
    public function drain(): array
    {
        $content = LockedFile::update($this->path, static fn (string $content) => [$content === '' ? null : '', $content]);
        $entries = [];
        foreach (explode("\n", self::keepLast((string) $content)) as $line) {
            $entry = $line === '' ? null : LogEntry::fromArray(json_decode($line, true));
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function clear(): void
    {
        LockedFile::update($this->path, static fn (string $content) => [$content === '' ? null : '', null]);
    }

    // Le tampon reste borné même quand le worker ne tourne pas : 1000 lignes au plus, et jamais plus de 1 Mo sur le disque.
    private static function keepLast(string $content, ?int $maxBytes = null): string
    {
        $lines = array_slice(array_values(array_filter(explode("\n", $content), static fn (string $line) => $line !== '')), -LogCollector::MAX_BUFFERED);
        $kept = [];
        $bytes = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $bytes += strlen($lines[$i]) + 1;
            if ($maxBytes !== null && $bytes > $maxBytes) {
                break;
            }
            $kept[] = $lines[$i];
        }

        return $kept === [] ? '' : implode("\n", array_reverse($kept)) . "\n";
    }
}
