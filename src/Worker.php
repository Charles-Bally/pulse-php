<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Logs\LogShipper;
use Pulse\Logs\SpoolHandler;

final class Worker
{
    public const FIRST_REPORT_DELAY_SECONDS = 2;
    private const MAX_SLEEP_SECONDS = 0.25;

    private float $nextReportAt = 0.0;
    private float $nextFlushAt = 0.0;
    private bool $stopping = false;
    private readonly \Closure $clock;

    public function __construct(
        public readonly Reporter $reporter,
        public readonly ?LogShipper $shipper,
        public readonly ServerState $state,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function start(): void
    {
        $now = ($this->clock)();
        $this->stopping = false;
        $this->nextReportAt = $now + self::FIRST_REPORT_DELAY_SECONDS;
        $this->nextFlushAt = $now + $this->state->flushDelaySeconds();
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /** @phpstan-impure */
    public function running(): bool
    {
        return !$this->stopping && !$this->reporter->stopped();
    }

    public function tick(): float
    {
        $now = ($this->clock)();
        if ($now >= $this->nextReportAt) {
            $this->reporter->reportNow();
            $this->nextReportAt = ($this->clock)() + $this->state->reportDelaySeconds();
        }
        if ($this->shipper !== null && $now >= $this->nextFlushAt) {
            $this->shipper->flush();
            $this->nextFlushAt = ($this->clock)() + $this->state->flushDelaySeconds();
        }
        if ($this->state->consumeBecameLive()) {
            $now = ($this->clock)();
            $this->nextReportAt = $now + ServerState::LIVE_INTERVAL_SECONDS;
            $this->nextFlushAt = min($this->nextFlushAt, $now + ServerState::LIVE_FLUSH_SECONDS);
        }

        return $this->shipper === null ? $this->nextReportAt : min($this->nextReportAt, $this->nextFlushAt);
    }

    public function run(?float $forSeconds = null): void
    {
        SpoolHandler::mute();
        $this->listenForSignals();
        $this->start();
        $deadline = $forSeconds === null ? null : ($this->clock)() + $forSeconds;
        while ($this->running() && ($deadline === null || ($this->clock)() < $deadline)) {
            $wakeAt = $this->tick();
            while ($this->running() && ($this->clock)() < $wakeAt && ($deadline === null || ($this->clock)() < $deadline)) {
                usleep((int) (1e6 * max(0.01, min(self::MAX_SLEEP_SECONDS, $wakeAt - ($this->clock)()))));
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }
        $this->shipper?->flush();
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop();
            });
        }
    }
}
