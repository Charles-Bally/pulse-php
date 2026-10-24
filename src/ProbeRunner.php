<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Support\Text;

final class ProbeRunner
{
    public const TIMEOUT_MS = 5000;

    /** @return array{name: string, kind: string, engine?: string, ok: bool, latencyMs: int, error?: string} */
    public static function run(Probe $probe, int $timeoutMs = self::TIMEOUT_MS): array
    {
        $timeout = "pas de réponse en {$timeoutMs} ms";
        $started = hrtime(true);
        $alarm = self::arm($timeoutMs);
        $error = null;
        try {
            $probe->run();
        } catch (ProbeTimeout) {
            $error = $timeout;
        } catch (\Throwable $exception) {
            $error = Sanitizer::error($exception);
        } finally {
            self::disarm($alarm);
        }
        $latency = (int) round((hrtime(true) - $started) / 1e6);
        if ($error === null && $latency > $timeoutMs) {
            $error = $timeout;
        }
        if ($error !== null) {
            $probe->failed();
        }
        $result = ['name' => Text::cut($probe->name, 60), 'kind' => $probe->kind];
        if ($probe->engine !== null && $probe->engine !== '') {
            $result['engine'] = Text::cut($probe->engine, 40);
        }
        $result['ok'] = $error === null;
        $result['latencyMs'] = $latency;
        if ($error !== null) {
            $result['error'] = $error;
        }

        return $result;
    }

    // PHP n'interrompt pas un appel bloquant de lui-même : SIGALRM coupe l'attente quand pcntl est là, sinon la durée mesurée tranche après coup.
    private static function arm(int $timeoutMs): mixed
    {
        if (PHP_SAPI !== 'cli' || $timeoutMs < 1000 || !function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals') || !pcntl_async_signals()) {
            return null;
        }
        $previous = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function (): void {
            throw new ProbeTimeout();
        }, false);
        pcntl_alarm((int) ceil($timeoutMs / 1000));

        return $previous;
    }

    private static function disarm(mixed $previous): void
    {
        if ($previous === null) {
            return;
        }
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, is_int($previous) || is_callable($previous) ? $previous : SIG_DFL);
    }
}
