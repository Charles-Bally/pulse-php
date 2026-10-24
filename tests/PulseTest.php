<?php

declare(strict_types=1);

namespace Pulse\Tests;

use Monolog\Logger as Monolog;
use PHPUnit\Framework\TestCase;
use Pulse\Config;
use Pulse\Http\Response;
use Pulse\Http\Transport;
use Pulse\Http\TransportException;
use Pulse\Logger;
use Pulse\Logs\LogSpool;
use Pulse\Logs\SpoolHandler;
use Pulse\PackageMeta;
use Pulse\Probe;
use Pulse\ProbeRunner;
use Pulse\Pulse;
use Pulse\Runtime;
use Pulse\Sanitizer;
use Pulse\Storage\StateStore;

final class PulseTest extends TestCase
{
    private const KEY = 'pk_live_8f2aQx3f9aB7cD1eF5gH9iJ2kL4mN6oP8qR';

    private string $directory;
    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pulse-test-' . bin2hex(random_bytes(4));
    }

    public function testReadsConfigurationAndRefusesWhatIsUnsafe(): void
    {
        $config = Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_APP_VERSION' => '2.3.0'], $this->logger());
        self::assertSame([self::KEY, 'https://api.pulse-app.si', '2.3.0'], [$config?->key, $config?->endpoint, $config?->appVersion]);

        $logger = $this->logger();
        self::assertNull(Config::resolve([], [], $logger));
        self::assertNull(Config::resolve([], ['PULSE_KEY' => 'sk_live_xxx'], $logger));
        self::assertNull(Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_ENDPOINT' => 'http://api.exemple.fr'], $logger));
        self::assertNull(Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_DISABLED' => 'true'], $logger));
        self::assertNull(Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_PUBLIC_KEY' => 'x'], $logger));
        self::assertNull(Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_RELAY_FINGERPRINT' => 'AB:CD'], $logger));
        self::assertSame([
            'PULSE_KEY absente : Pulse ne surveille pas cette app.',
            'PULSE_KEY mal formée : elle commence par pk_live_ ou pk_test_.',
            'PULSE_ENDPOINT doit être en https.',
            'PULSE_PUBLIC_KEY mal formée : copie-la depuis l’app Pulse, elle commence par pulse_pub_.',
            'PULSE_RELAY_FINGERPRINT mal formée : copie l’empreinte SHA-256 affichée par ton relais.',
        ], $logger->lines);
        self::assertStringNotContainsString(self::KEY, implode("\n", $logger->lines));

        $local = Config::resolve(['endpoint' => 'http://localhost:3200/chemin?x=1'], ['PULSE_KEY' => self::KEY], $logger);
        self::assertSame('http://localhost:3200', $local?->endpoint);
        self::assertSame('https://relais.helio.fr', Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_ENDPOINT' => 'https://relais.helio.fr:443/'], $logger)?->endpoint);
        $pin = str_repeat('ab:', 31) . 'ab';
        self::assertSame(strtoupper($pin), Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_RELAY_FINGERPRINT' => " {$pin} "], $logger)?->fingerprint);
    }

    public function testChoosesWhatLeaves(): void
    {
        $runtime = $this->runtime(['PULSE_SEND' => 'checks, logs', 'PULSE_EXCLUDE' => 'File *', 'PULSE_LOG_LEVEL' => 'warn'], new FakeTransport());
        $runtime->reporter->addProbe(new Probe('File emails', 'queue', static fn () => 1));
        $runtime->reporter->addProbe(new Probe('Redis', 'redis', static fn () => 'PONG'));
        $runtime->reporter->addProbe(new Probe('Redis', 'redis', static fn () => 'PONG'));
        $runtime->reporter->addCronSource(static fn () => [['name' => 'relance']]);
        $report = $runtime->reporter->buildReport();
        self::assertSame(['Redis'], array_column($report['checks'], 'name'));
        self::assertSame([], $report['crons']);
        self::assertArrayNotHasKey('appVersion', $report);
        self::assertArrayNotHasKey('deployedAt', $report);

        $collector = $runtime->shipper?->collector;
        $collector?->push(['at' => '2026-10-20T10:00:00.000Z', 'level' => 'INFO', 'message' => 'bavard']);
        $collector?->push(['at' => '2026-10-20T10:00:00.000Z', 'level' => 'ERROR', 'message' => 'utile']);
        self::assertSame(['utile'], array_column($collector?->take(10) ?? [], 'message'));
        self::assertNull($this->runtime(['PULSE_LOGS' => 'false'], new FakeTransport())->shipper);
        self::assertNull($this->runtime(['PULSE_SEND' => 'checks'], new FakeTransport())->shipper);

        $only = $this->runtime(['PULSE_INCLUDE' => 'postgresql,relance-*'], new FakeTransport())->reporter;
        $only->addProbe(new Probe('PostgreSQL', 'database', static fn () => 1));
        $only->addProbe(new Probe('Redis', 'redis', static fn () => 1));
        self::assertSame(['PostgreSQL'], $only->probeNames());
    }

    public function testCleansErrorsBeforeTheyLeave(): void
    {
        $error = new \PDOException('connect failed postgres://helio:motdepasse@db:5432/helio?sslmode=require');
        self::assertSame('connect failed postgres://***@db:5432/helio?sslmode=require', Sanitizer::error($error));
        self::assertSame('ECONNREFUSED refus', Sanitizer::error(new CodedError('refus', 'ECONNREFUSED')));
        self::assertSame('GET https://api.exemple.fr/v1 failed', Sanitizer::error(new \RuntimeException('GET https://api.exemple.fr/v1?token=abc failed')));
        self::assertSame('auth password=*** refused', Sanitizer::error('auth password=hunter2 refused'));
        self::assertSame('Authorization: Bearer *** pk_live_***', Sanitizer::redactSecrets('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9 ' . self::KEY));
        self::assertSame(300, strlen(Sanitizer::error(str_repeat('x', 400))));
        self::assertSame('erreur inconnue', Sanitizer::error(new \RuntimeException('  ')));
    }

    public function testMeasuresProbesAndTheirDelay(): void
    {
        self::assertSame(['name' => 'Redis', 'kind' => 'redis', 'engine' => 'redis', 'ok' => true], array_diff_key(
            ProbeRunner::run(new Probe('Redis', 'redis', static fn () => 'PONG', 'redis')),
            ['latencyMs' => 0],
        ));
        $failed = ProbeRunner::run(new Probe('PostgreSQL', 'database', static fn () => throw new \RuntimeException('down')));
        self::assertSame([false, 'down'], [$failed['ok'], $failed['error'] ?? null]);
        $slow = ProbeRunner::run(new Probe('Lente', 'custom', static fn () => usleep(40000)), 20);
        self::assertSame([false, 'pas de réponse en 20 ms'], [$slow['ok'], $slow['error'] ?? null]);
        self::assertGreaterThanOrEqual(20, $slow['latencyMs']);

        $purged = 0;
        ProbeRunner::run(new Probe('Redis', 'redis', static fn () => throw new \RuntimeException('down'), 'redis', static function () use (&$purged): void {
            $purged++;
        }));
        self::assertSame(1, $purged);
        $this->expectException(\InvalidArgumentException::class);
        new Probe('X', 'inconnu', static fn () => null);
    }

    public function testTracksTasksAcrossProcesses(): void
    {
        $pulse = Pulse::in($this->directory);
        self::assertSame(42, $pulse->track('relance-abonnements', static fn () => 42));
        try {
            $pulse->track('nettoyage', static fn () => throw new \RuntimeException('timeout token=abc'));
            self::fail('l’erreur de la tâche doit remonter');
        } catch (\RuntimeException $error) {
            self::assertSame('timeout token=abc', $error->getMessage());
        }
        Pulse::disabled()->track('rien', static fn () => null);

        $runtime = $this->runtime([], new FakeTransport());
        $next = new \DateTimeImmutable('2026-10-22T09:00:00Z');
        $runtime->reporter->addCronSource(static fn () => [
            ['name' => 'relance-abonnements', 'schedule' => '0 * * * *', 'nextRunAt' => $next],
            ['name' => 'rapport-hebdo', 'schedule' => '0 8 * * 1'],
        ]);
        $crons = array_column($runtime->reporter->buildReport()['crons'], null, 'name');
        self::assertSame(['schedule' => '0 * * * *', 'nextRunAt' => '2026-10-22T09:00:00.000Z', 'lastRunOk' => true], array_intersect_key(
            $crons['relance-abonnements'],
            ['schedule' => 1, 'nextRunAt' => 1, 'lastRunOk' => 1],
        ));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $crons['relance-abonnements']['lastRunAt']);
        self::assertSame([false, 'timeout token=***'], [$crons['nettoyage']['lastRunOk'], $crons['nettoyage']['error']]);
        self::assertSame(['name' => 'rapport-hebdo', 'schedule' => '0 8 * * 1'], $crons['rapport-hebdo']);
        self::assertArrayNotHasKey('rien', $crons);
    }

    public function testSendsAFullReportAndFollowsTheApiRhythm(): void
    {
        $transport = new FakeTransport(new Response(202, '{"accepted":true,"intervalSeconds":60,"keyId":null,"live":false,"logs":true}'));
        $runtime = $this->runtime(['PULSE_APP_VERSION' => '2.3.0'], $transport);
        $runtime->reporter->addProbe(new Probe('Redis', 'redis', static fn () => 'PONG', 'redis'));
        $runtime->reporter->addCronSource(static fn () => [['name' => 'relance-abonnements', 'schedule' => '0 * * * *']]);
        Pulse::in($this->directory)->recordCronRun('relance-abonnements', false, new \RuntimeException('timeout'));

        self::assertSame(60, $runtime->reporter->reportNow()['intervalSeconds'] ?? null);
        [$url, $headers, $body] = $transport->calls[0];
        self::assertSame('https://api.pulse-app.si/ingest/report', $url);
        self::assertSame(['content-type' => 'application/json', 'x-pulse-key' => self::KEY, 'user-agent' => 'pulse-laravel/0.1.0'], $headers);
        $report = json_decode($body, true);
        self::assertSame(['pulse-laravel', '0.1.0', 'laravel', '12.3.0', '2.3.0'], [
            $report['packageName'], $report['packageVersion'], $report['framework'], $report['frameworkVersion'], $report['appVersion'],
        ]);
        self::assertSame(['name' => 'Redis', 'kind' => 'redis', 'engine' => 'redis', 'ok' => true], array_diff_key($report['checks'][0], ['latencyMs' => 0]));
        self::assertSame(['relance-abonnements', '0 * * * *', false, 'timeout'], [
            $report['crons'][0]['name'], $report['crons'][0]['schedule'], $report['crons'][0]['lastRunOk'], $report['crons'][0]['error'],
        ]);
        self::assertTrue((new StateStore($this->directory . '/state.json'))->logsOn());

        $worker = $runtime->worker;
        $this->now = 0.0;
        $worker->start();
        $transport->calls = [];
        $this->now = 1.9;
        $worker->tick();
        self::assertCount(0, $transport->calls);
        $this->now = 2.0;
        $worker->tick();
        self::assertCount(1, $transport->calls);
        $this->now = 61.0;
        $worker->tick();
        self::assertCount(1, $transport->calls);
        $transport->responses = [new Response(202, '{"accepted":true,"intervalSeconds":60,"live":true,"logs":true}')];
        $this->now = 62.0;
        $worker->tick();
        self::assertSame(5, $runtime->worker->state->reportDelaySeconds());
        self::assertSame(1, $runtime->worker->state->flushDelaySeconds());
        $this->now = 67.0;
        $worker->tick();
        self::assertCount(3, array_filter($transport->calls, static fn (array $call) => str_ends_with($call[0], '/ingest/report')));
    }

    public function testNeverThrowsWarnsOnceAndStopsOnRefusal(): void
    {
        $logger = $this->logger();
        $offline = new FakeTransport(new TransportException('Could not resolve host'));
        $reporter = $this->runtime([], $offline, $logger)->reporter;
        self::assertNull($reporter->reportNow());
        self::assertNull($reporter->reportNow());
        self::assertSame(['Pulse injoignable : Could not resolve host'], $logger->lines);

        $revoked = $this->runtime([], new FakeTransport(new Response(401)), $logger)->reporter;
        self::assertNull($revoked->reportNow());
        self::assertTrue($revoked->stopped());
        self::assertSame('Clé Pulse refusée : vérifie PULSE_KEY. Les rapports s’arrêtent.', end($logger->lines));

        $required = $this->runtime([], new FakeTransport(new Response(409, '{"code":"secure_mode_required"}')), $logger)->reporter;
        self::assertNull($required->reportNow());
        self::assertTrue($required->stopped());
        self::assertStringContainsString('Les rapports en clair s’arrêtent', (string) end($logger->lines));
        $mismatch = $this->runtime([], new FakeTransport(new Response(409, '{"code":"seal_key_mismatch"}')), $logger)->reporter;
        $mismatch->reportNow();
        self::assertFalse($mismatch->stopped());
        self::assertStringContainsString('ne correspond plus', (string) end($logger->lines));
        $this->runtime([], new FakeTransport(new Response(500)), $logger)->reporter->reportNow();
        self::assertSame('Pulse injoignable : réponse 500', end($logger->lines));
    }

    public function testCopiesLogsThroughTheSpoolOnlyWhenTheOfferAllowsIt(): void
    {
        $monolog = new Monolog('paiements', [SpoolHandler::in($this->directory, 'INFO')]);
        $monolog->error('Paiement {id} refusé token=abc123', ['id' => 42]);
        $spool = LogSpool::in($this->directory);
        self::assertSame([], $spool->drain());

        $transport = new FakeTransport(
            new Response(202, '{"accepted":true,"intervalSeconds":30,"logs":true,"live":false}'),
            new Response(202, '{"logs":true,"live":true}'),
            new TransportException('réseau coupé'),
            new Response(202, '{"logs":false,"live":false}'),
        );
        $runtime = $this->runtime([], $transport);
        $runtime->reporter->reportNow();
        $monolog->debug('bavard');
        $monolog->error('Paiement {id} refusé token=abc123', ['id' => 42]);
        $line = __LINE__ + 1;
        $monolog->warning('Lent', ['exception' => new \RuntimeException('délai')]);
        self::assertSame(2, $runtime->shipper?->flush());
        $entries = json_decode($transport->calls[1][2], true)['entries'];
        self::assertSame(['ERROR', 'paiements', 'Paiement 42 refusé token=***'], [$entries[0]['level'], $entries[0]['context'], $entries[0]['message']]);
        self::assertSame("Lent\nRuntimeException: délai (PulseTest.php:{$line})", $entries[1]['message']);
        self::assertTrue($runtime->worker->state->live());

        $monolog->error('pendant la coupure');
        self::assertSame(0, $runtime->shipper?->flush());
        self::assertSame(1, $runtime->shipper?->collector->size());
        self::assertSame(1, $runtime->shipper?->flush());
        self::assertFalse((new StateStore($this->directory . '/state.json'))->logsOn());
        $monolog->error('après la fin de l’offre');
        self::assertSame([], $spool->drain());

        for ($i = 0; $i < 1500; $i++) {
            $spool->append(['at' => '2026-10-22T08:00:00.000Z', 'level' => 'INFO', 'message' => str_repeat('x', 1000) . $i]);
        }
        self::assertLessThanOrEqual(1100000, (int) filesize($this->directory . '/logs.ndjson'));
        $kept = $spool->drain();
        self::assertLessThanOrEqual(1000, count($kept));
        self::assertStringEndsWith('1499', end($kept)['message']);
        self::assertSame([], $spool->drain());
    }

    /** @param array<string, string> $env */
    private function runtime(array $env, FakeTransport $transport, ?Logger $logger = null): Runtime
    {
        $runtime = Runtime::create(
            new PackageMeta('pulse-laravel', '0.1.0', 'laravel', '12.3.0'),
            $this->directory,
            [],
            ['PULSE_KEY' => self::KEY] + $env,
            $logger ?? $this->logger(),
            $transport,
            fn (): float => $this->now,
        );
        self::assertNotNull($runtime);

        return $runtime;
    }

    private function logger(): MemoryLogger
    {
        return new MemoryLogger();
    }
}

final class MemoryLogger implements Logger
{
    /** @var list<string> */
    public array $lines = [];

    public function warn(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->lines[] = $message;
    }
}

final class CodedError extends \RuntimeException
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message);
        $this->code = $code;
    }
}

final class FakeTransport implements Transport
{
    /** @var list<array{0: string, 1: array<string, string>, 2: string}> */
    public array $calls = [];
    /** @var list<Response|\Throwable> */
    public array $responses;

    public function __construct(Response|\Throwable ...$responses)
    {
        $this->responses = $responses === [] ? [new Response(202, '{"accepted":true,"intervalSeconds":60}')] : array_values($responses);
    }

    public function post(string $url, array $headers, string $body): Response
    {
        $this->calls[] = [$url, $headers, $body];
        $next = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
