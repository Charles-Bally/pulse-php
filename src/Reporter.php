<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Crypto\SecureReport;
use Pulse\Http\Api;
use Pulse\Storage\CronStore;
use Pulse\Support\Text;

final class Reporter
{
    public const MAX_CHECKS = 20;
    public const MAX_CRONS = 50;
    private const SECURE_REFUSALS = [
        'secure_mode_required' => 'Mode sécurisé actif pour cette app : ajoute PULSE_PUBLIC_KEY. Les rapports en clair s’arrêtent.',
        'seal_key_mismatch' => 'PULSE_PUBLIC_KEY ne correspond plus à la clé de l’app : recopie-la depuis l’app Pulse.',
        'secure_mode_off' => 'Le mode sécurisé est coupé pour cette app : retire PULSE_PUBLIC_KEY ou réactive-le dans l’app.',
    ];

    /** @var list<Probe> */
    private array $probes = [];
    /** @var list<\Closure(): iterable<array{name: string, schedule?: ?string, nextRunAt?: ?\DateTimeInterface, lastRunAt?: ?\DateTimeInterface}>> */
    private array $cronSources = [];
    private readonly \DateTimeImmutable $startedAt;
    private string $lastFailure = '';
    private bool $stopped = false;
    /** @var array<string, mixed>|null */
    private ?array $lastReport = null;

    public function __construct(
        private readonly PackageMeta $meta,
        private readonly Config $config,
        private readonly Api $api,
        private readonly ServerState $state,
        private readonly CronStore $crons,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function addProbe(Probe $probe): self
    {
        if (!$this->config->selection->allows($probe->name)) {
            return $this;
        }
        foreach ($this->probes as $known) {
            if ($known->name === $probe->name) {
                return $this;
            }
        }
        $this->probes[] = $probe;

        return $this;
    }

    /** @return list<string> */
    public function probeNames(): array
    {
        return array_map(static fn (Probe $probe) => $probe->name, $this->probes);
    }

    /** @param callable(): iterable<array{name: string, schedule?: ?string, nextRunAt?: ?\DateTimeInterface, lastRunAt?: ?\DateTimeInterface}> $source */
    public function addCronSource(callable $source): self
    {
        $this->cronSources[] = \Closure::fromCallable($source);

        return $this;
    }

    // Ne lève jamais : une panne de Pulse ne doit pas faire tomber l'app surveillée.
    /** @return array<string, mixed>|null */
    public function reportNow(): ?array
    {
        if ($this->stopped) {
            return null;
        }
        try {
            $report = $this->lastReport = $this->buildReport();
            $secure = $this->config->publicKey;
            $body = $secure === null ? $report : SecureReport::payload($report, $secure, $this->config->clear);
            $response = $this->api->post($secure === null ? '/ingest/report' : '/ingest/report/sealed', $body);
            if ($response->status === 401) {
                $this->config->logger->error('Clé Pulse refusée : vérifie PULSE_KEY. Les rapports s’arrêtent.');
                $this->stop();

                return null;
            }
            if ($response->status === 409) {
                $code = (string) ($response->json()['code'] ?? '');
                $this->warnOnce(self::SECURE_REFUSALS[$code] ?? 'Rapport refusé par Pulse.');
                if ($code === 'secure_mode_required') {
                    $this->stop();
                }

                return null;
            }
            if (!$response->ok()) {
                throw new \RuntimeException("réponse {$response->status}");
            }
            $ack = $response->json();
            $this->state->adoptInterval($ack['intervalSeconds'] ?? null);
            $this->state->adopt(($ack['logs'] ?? false) === true, ($ack['live'] ?? false) === true);
            $this->lastFailure = '';

            return $ack;
        } catch (\Throwable $error) {
            $this->warnOnce('Pulse injoignable : ' . Sanitizer::error($error));

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function lastReport(): ?array
    {
        return $this->lastReport;
    }

    /** @return array<string, mixed> */
    public function buildReport(): array
    {
        $selection = $this->config->selection;
        $report = [
            'packageName' => $this->meta->packageName,
            'packageVersion' => $this->meta->packageVersion,
            'framework' => $this->meta->framework,
        ];
        if ($selection->wants('versions')) {
            $report += array_filter([
                'frameworkVersion' => $this->meta->frameworkVersion === null ? null : Text::cut($this->meta->frameworkVersion, 30),
                'appVersion' => $this->config->appVersion === null ? null : Text::cut($this->config->appVersion, 60),
                'deployedAt' => Text::iso($this->startedAt),
            ], static fn ($value) => $value !== null && $value !== '');
        }
        $report['checks'] = $selection->wants('checks')
            ? array_map(static fn (Probe $probe) => ProbeRunner::run($probe), array_slice($this->probes, 0, self::MAX_CHECKS))
            : [];
        $report['crons'] = $selection->wants('crons') ? $this->crons() : [];

        return $report;
    }

    public function warnOnce(string $message): void
    {
        if ($message === $this->lastFailure) {
            return;
        }
        $this->lastFailure = $message;
        $this->config->logger->warn($message);
    }

    /** @return list<array<string, mixed>> */
    private function crons(): array
    {
        $snapshots = [];
        foreach ($this->cronSources as $source) {
            try {
                foreach ($source() as $cron) {
                    $snapshots[$cron['name']] = $cron;
                }
            } catch (\Throwable $error) {
                $this->warnOnce('Lecture des tâches planifiées impossible : ' . Sanitizer::error($error));
            }
        }
        foreach ($this->crons->all() as $name => $run) {
            $snapshots[$name] = array_merge($snapshots[$name] ?? [], [
                'name' => $name,
                'lastRunAt' => new \DateTimeImmutable($run['at']),
                'lastRunOk' => $run['ok'],
                'error' => $run['error'] ?? null,
            ]);
        }
        $out = [];
        foreach ($snapshots as $cron) {
            if (count($out) >= self::MAX_CRONS || !$this->config->selection->allows((string) $cron['name'])) {
                continue;
            }
            $out[] = self::cronPayload($cron);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $cron
     * @return array<string, mixed>
     */
    private static function cronPayload(array $cron): array
    {
        $date = static fn (mixed $value) => $value instanceof \DateTimeInterface ? Text::iso($value) : null;
        $text = static fn (mixed $value, int $max) => is_string($value) && trim($value) !== '' ? Text::cut(trim($value), $max) : null;

        return array_filter([
            'name' => Text::cut((string) $cron['name'], 80),
            'schedule' => $text($cron['schedule'] ?? null, 80),
            'lastRunAt' => $date($cron['lastRunAt'] ?? null),
            'nextRunAt' => $date($cron['nextRunAt'] ?? null),
            'lastRunOk' => is_bool($cron['lastRunOk'] ?? null) ? $cron['lastRunOk'] : null,
            'error' => $text($cron['error'] ?? null, 300),
        ], static fn ($value) => $value !== null);
    }
}
