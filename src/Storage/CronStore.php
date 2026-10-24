<?php

declare(strict_types=1);

namespace Pulse\Storage;

use Pulse\Support\Text;

final class CronStore
{
    private const MAX_TASKS = 100;

    public function __construct(private readonly string $path)
    {
    }

    public static function in(string $directory): self
    {
        return new self(rtrim($directory, '/') . '/crons.json');
    }

    // Les passages viennent d'autres processus (schedule:run, messenger:consume) : ils passent par ce fichier partagé.
    public function record(string $name, bool $ok, ?string $error = null, ?\DateTimeInterface $at = null): void
    {
        try {
            LockedFile::update($this->path, function (string $content) use ($name, $ok, $error, $at): array {
                $runs = self::decode($content);
                $runs[$name] = array_filter(
                    ['at' => Text::iso($at ?? new \DateTimeImmutable()), 'ok' => $ok, 'error' => $ok ? null : $error],
                    static fn ($value) => $value !== null,
                );
                uasort($runs, static fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

                return [Text::json(array_slice($runs, 0, self::MAX_TASKS, true)), null];
            });
        } catch (\Throwable) {
        }
    }

    /** @return array<string, array{at: string, ok: bool, error?: string}> */
    public function all(): array
    {
        return self::decode((string) LockedFile::read($this->path));
    }

    /** @return array<string, array{at: string, ok: bool, error?: string}> */
    private static function decode(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        $runs = [];
        foreach ($data as $name => $run) {
            if (is_string($name) && is_array($run) && is_string($run['at'] ?? null) && is_bool($run['ok'] ?? null)) {
                $runs[$name] = array_filter(
                    ['at' => $run['at'], 'ok' => $run['ok'], 'error' => is_string($run['error'] ?? null) ? $run['error'] : null],
                    static fn ($value) => $value !== null,
                );
            }
        }

        return $runs;
    }
}
