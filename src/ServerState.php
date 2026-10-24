<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Storage\StateStore;

final class ServerState
{
    public const DEFAULT_INTERVAL_SECONDS = 30;
    public const MIN_INTERVAL_SECONDS = 10;
    public const MAX_INTERVAL_SECONDS = 3600;
    public const LIVE_INTERVAL_SECONDS = 5;
    public const NORMAL_FLUSH_SECONDS = 10;
    public const LIVE_FLUSH_SECONDS = 1;
    private const STATE_GRACE_SECONDS = 60;

    private int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS;
    private bool $logs = false;
    private bool $live = false;
    private bool $becameLive = false;

    public function __construct(private readonly ?StateStore $store = null)
    {
    }

    public function intervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function logs(): bool
    {
        return $this->logs;
    }

    public function live(): bool
    {
        return $this->live;
    }

    public function adoptInterval(mixed $seconds): void
    {
        if (is_int($seconds) && $seconds > 0) {
            $this->intervalSeconds = min(max($seconds, self::MIN_INTERVAL_SECONDS), self::MAX_INTERVAL_SECONDS);
        }
    }

    // Les autres processus de l'app lisent cet état pour savoir s'ils doivent recopier leurs journaux.
    public function adopt(bool $logs, bool $live): void
    {
        if ($live && !$this->live) {
            $this->becameLive = true;
        }
        $this->logs = $logs;
        $this->live = $live;
        $this->store?->write($logs, $live, 2 * $this->intervalSeconds + self::STATE_GRACE_SECONDS);
    }

    public function consumeBecameLive(): bool
    {
        $became = $this->becameLive;
        $this->becameLive = false;

        return $became;
    }

    public function reportDelaySeconds(): int
    {
        return $this->live ? min(self::LIVE_INTERVAL_SECONDS, $this->intervalSeconds) : $this->intervalSeconds;
    }

    public function flushDelaySeconds(): int
    {
        return $this->live ? self::LIVE_FLUSH_SECONDS : self::NORMAL_FLUSH_SECONDS;
    }
}
