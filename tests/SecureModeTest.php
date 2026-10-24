<?php

declare(strict_types=1);

namespace Pulse\Tests;

use PHPUnit\Framework\TestCase;
use Pulse\Config;
use Pulse\Crypto\Hpke;
use Pulse\Crypto\Sealer;
use Pulse\Http\Response;
use Pulse\Http\Transport;
use Pulse\PackageMeta;
use Pulse\Probe;
use Pulse\Runtime;
use Pulse\Support\Loggers;

final class SecureModeTest extends TestCase
{
    private const KEY = 'pk_live_8f2aQx3f9aB7cD1eF5gH9iJ2kL4mN6oP8qR';

    public function testFollowsRfc9180Vectors(): void
    {
        $hex = static fn (string $value): string => (string) hex2bin($value);
        $recipient = $hex('3948cfe0ad1ddb695d780e59077195da6c56506b027329794ab02bca80815c4d');
        $info = $hex('4f6465206f6e2061204772656369616e2055726e');
        $aad = $hex('436f756e742d30');
        $plaintext = $hex('4265617574792069732074727574682c20747275746820626561757479');
        $sealed = Hpke::seal($recipient, $info, $aad, $plaintext, 'aes-128-gcm', $hex('52c4a758a802cd8b936eceea314432798d5baf2d7e9235dc084ab1b9cfa2f736'));
        self::assertSame('37fda3567bdbd628e88668c3c8d7e97d1d1253b6d4ea6d44c150f741f1bf4431', bin2hex($sealed['enc']));
        self::assertSame('f938558b5d72f1a23810b4be2ab4f84331acc02fc97babc53a52ae8218a355a96d8770ac83d07bea87e13c512a', bin2hex($sealed['ct']));
        $privateKey = $hex('4612c550263fc8ad58375df3f557aac531d26850903e55a9f23f21d8534e8ac8');
        self::assertSame($plaintext, Hpke::open($privateKey, $info, $aad, $sealed['enc'], $sealed['ct'], 'aes-128-gcm'));
    }

    public function testSealsTheWholeReportAndLeavesOnlyChosenFieldsClear(): void
    {
        $pair = Hpke::generateKeyPair();
        $transport = new SealedTransport();
        $runtime = self::runtime(['PULSE_PUBLIC_KEY' => Sealer::formatPublicKey($pair['publicKey'])], $transport);
        $runtime->reporter->addProbe(new Probe('PostgreSQL', 'database', static fn () => throw new \RuntimeException('down')));
        $runtime->reporter->reportNow();
        [$url, , $body] = $transport->calls[0];
        self::assertStringEndsWith('/ingest/report/sealed', $url);
        self::assertStringNotContainsString('PostgreSQL', $body);
        $payload = json_decode($body, true);
        self::assertSame(['status' => ['ok' => false, 'problems' => 1]], $payload['clear']);
        self::assertSame(['v', 'kid', 'enc', 'ct'], array_keys($payload['sealed']));
        $report = Sealer::open($pair['privateKey'], $pair['publicKey'], 'report', $payload['sealed']);
        self::assertSame('PostgreSQL', $report['checks'][0]['name']);
        try {
            Sealer::open($pair['privateKey'], $pair['publicKey'], 'logs', $payload['sealed']);
            self::fail('un rapport ne doit pas s’ouvrir comme des journaux');
        } catch (\RuntimeException $error) {
            self::assertSame('déchiffrement impossible', $error->getMessage());
        }

        $runtime->shipper?->collector->push(['at' => '2026-10-22T08:00:00.000Z', 'level' => 'ERROR', 'message' => 'secret métier']);
        $runtime->shipper?->flush();
        [$logsUrl, , $logsBody] = $transport->calls[1];
        self::assertStringEndsWith('/ingest/logs/sealed', $logsUrl);
        $logs = json_decode($logsBody, true);
        self::assertSame([1, '2026-10-22T08:00:00.000Z'], [$logs['count'], $logs['at']]);
        self::assertSame('secret métier', Sealer::open($pair['privateKey'], $pair['publicKey'], 'logs', $logs['sealed'])[0]['message']);

        $silent = self::runtime(['PULSE_PUBLIC_KEY' => Sealer::formatPublicKey($pair['publicKey']), 'PULSE_CLEAR' => 'none'], $transport);
        $silent->reporter->reportNow();
        self::assertStringContainsString('"clear":{}', end($transport->calls)[2]);
        self::assertSame(['checks', 'crons'], Config::resolve([], ['PULSE_KEY' => self::KEY, 'PULSE_PUBLIC_KEY' => Sealer::formatPublicKey($pair['publicKey']), 'PULSE_CLEAR' => 'crons, checks'])?->clear);
    }

    /** @param array<string, string> $env */
    private static function runtime(array $env, Transport $transport): Runtime
    {
        $directory = sys_get_temp_dir() . '/pulse-seal-' . bin2hex(random_bytes(4));
        $runtime = Runtime::create(new PackageMeta('pulse-symfony', '0.1.0', 'symfony'), $directory, [], ['PULSE_KEY' => self::KEY] + $env, Loggers::none(), $transport);
        self::assertNotNull($runtime);

        return $runtime;
    }
}

final class SealedTransport implements Transport
{
    /** @var list<array{0: string, 1: array<string, string>, 2: string}> */
    public array $calls = [];

    public function post(string $url, array $headers, string $body): Response
    {
        $this->calls[] = [$url, $headers, $body];

        return new Response(202, str_contains($url, '/ingest/logs') ? '{"logs":true,"live":false}' : '{"accepted":true,"intervalSeconds":30,"logs":true}');
    }
}
