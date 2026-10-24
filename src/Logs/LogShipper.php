<?php

declare(strict_types=1);

namespace Pulse\Logs;

use Pulse\Config;
use Pulse\Crypto\Sealer;
use Pulse\Http\Api;
use Pulse\Sanitizer;
use Pulse\ServerState;

final class LogShipper
{
    public const BATCH_SIZE = 200;

    public readonly LogCollector $collector;
    private bool $stopped = false;

    /** @param \Closure(string): void $warn */
    public function __construct(
        private readonly Config $config,
        private readonly Api $api,
        private readonly ServerState $state,
        private readonly ?LogSpool $spool,
        private readonly \Closure $warn,
    ) {
        $this->collector = new LogCollector($config->selection->logLevel);
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    public function flush(): int
    {
        if ($this->stopped) {
            return 0;
        }
        if (!$this->state->logs()) {
            $this->spool?->clear();
            $this->collector->clear();

            return 0;
        }
        foreach ($this->spool?->drain() ?? [] as $entry) {
            $this->collector->push($entry);
        }
        if ($this->collector->size() === 0) {
            return 0;
        }
        $entries = $this->collector->take(self::BATCH_SIZE);
        $secure = $this->config->publicKey;
        $body = $secure === null
            ? ['entries' => $entries]
            : ['sealed' => Sealer::seal($secure, 'logs', $entries), 'count' => count($entries), 'at' => $entries[0]['at']];
        try {
            $response = $this->api->post($secure === null ? '/ingest/logs' : '/ingest/logs/sealed', $body);
            if ($response->status === 401 || $response->status === 409) {
                $this->stopped = true;

                return 0;
            }
            if (!$response->ok()) {
                throw new \RuntimeException("réponse {$response->status}");
            }
            $ack = $response->json();
            $this->state->adopt(($ack['logs'] ?? false) === true, ($ack['live'] ?? false) === true);

            return count($entries);
        } catch (\Throwable $error) {
            $this->collector->putBack($entries);
            ($this->warn)('Journaux non envoyés à Pulse : ' . Sanitizer::error($error));

            return 0;
        }
    }
}
