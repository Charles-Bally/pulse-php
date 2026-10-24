<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Storage\CronStore;

final class Pulse
{
    public function __construct(private readonly ?CronStore $crons = null)
    {
    }

    public static function in(string $directory): self
    {
        return new self(CronStore::in($directory));
    }

    public static function disabled(): self
    {
        return new self();
    }

    /**
     * @template T
     * @param callable(): T $task
     * @return T
     */
    public function track(string $name, callable $task): mixed
    {
        try {
            $result = $task();
        } catch (\Throwable $error) {
            $this->recordCronRun($name, false, $error);
            throw $error;
        }
        $this->recordCronRun($name, true);

        return $result;
    }

    public function recordCronRun(string $name, bool $ok, mixed $error = null): void
    {
        $this->crons?->record($name, $ok, $ok || $error === null ? null : Sanitizer::error($error));
    }
}
