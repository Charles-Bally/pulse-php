<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Crypto\Sealer;
use Pulse\Support\Loggers;
use Pulse\Support\Text;

final class Config
{
    public const DEFAULT_ENDPOINT = 'https://api.pulse-app.si';
    public const CLEAR_FIELDS = ['status', 'checks', 'crons', 'versions'];
    private const KEY_PATTERN = '/^pk_(live|test)_[A-Za-z0-9_-]{20,}$/';
    private const FINGERPRINT_PATTERN = '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/';
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** @param list<string> $clear */
    public function __construct(
        public readonly string $key,
        public readonly string $endpoint,
        public readonly ?string $appVersion,
        public readonly ?string $publicKey,
        public readonly array $clear,
        public readonly Selection $selection,
        public readonly Logger $logger,
        public readonly ?string $fingerprint = null,
    ) {
    }

    public function logs(): bool
    {
        return $this->selection->wants('logs');
    }

    public function secure(): bool
    {
        return $this->publicKey !== null;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public static function normalizeFingerprint(?string $raw): ?string
    {
        $value = strtoupper(trim((string) $raw));

        return preg_match(self::FINGERPRINT_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string>|null $env
     */
    public static function resolve(array $options = [], ?array $env = null, ?Logger $logger = null): ?self
    {
        $env ??= Env::current();
        $logger ??= Loggers::stderr();
        $disabled = Text::bool($options['disabled'] ?? null) ?? (($env['PULSE_DISABLED'] ?? '') === 'true');
        if ($disabled) {
            return null;
        }
        $key = trim(self::pick($options, 'key', $env, 'PULSE_KEY') ?? '');
        if ($key === '') {
            $logger->warn('PULSE_KEY absente : Pulse ne surveille pas cette app.');

            return null;
        }
        if (!self::isValidKey($key)) {
            $logger->error('PULSE_KEY mal formée : elle commence par pk_live_ ou pk_test_.');

            return null;
        }
        $endpoint = self::safeEndpoint(self::pick($options, 'endpoint', $env, 'PULSE_ENDPOINT') ?? self::DEFAULT_ENDPOINT);
        if ($endpoint === null) {
            $logger->error('PULSE_ENDPOINT doit être en https.');

            return null;
        }
        $rawPublicKey = self::pick($options, 'public_key', $env, 'PULSE_PUBLIC_KEY');
        $publicKey = $rawPublicKey === null ? null : Sealer::parsePublicKey($rawPublicKey);
        if ($rawPublicKey !== null && $publicKey === null) {
            $logger->error('PULSE_PUBLIC_KEY mal formée : copie-la depuis l’app Pulse, elle commence par pulse_pub_.');

            return null;
        }
        if ($publicKey !== null && !Sealer::available()) {
            $logger->error('Mode sécurisé impossible : PHP n’a pas les extensions sodium et openssl. Rien ne part en clair.');

            return null;
        }
        $rawFingerprint = self::pick($options, 'relay_fingerprint', $env, 'PULSE_RELAY_FINGERPRINT');
        $fingerprint = $rawFingerprint === null ? null : self::normalizeFingerprint($rawFingerprint);
        if ($rawFingerprint !== null && $fingerprint === null) {
            $logger->error('PULSE_RELAY_FINGERPRINT mal formée : copie l’empreinte SHA-256 affichée par ton relais.');

            return null;
        }
        if ($fingerprint !== null && !function_exists('openssl_x509_fingerprint')) {
            $logger->error('PULSE_RELAY_FINGERPRINT demande l’extension openssl de PHP : rien ne part.');

            return null;
        }

        return new self(
            $key,
            $endpoint,
            self::pick($options, 'app_version', $env, 'PULSE_APP_VERSION'),
            $publicKey,
            self::clearFields($options['clear'] ?? null, $env['PULSE_CLEAR'] ?? null),
            Selection::resolve($options, $env),
            $logger,
            $fingerprint,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $env
     */
    private static function pick(array $options, string $option, array $env, string $variable): ?string
    {
        $value = $options[$option] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $fromEnv = $env[$variable] ?? null;

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
    }

    // La clé voyage dans un en-tête : jamais en clair hors de la machine, sauf vers un Pulse local.
    private static function safeEndpoint(string $raw): ?string
    {
        $parts = parse_url(trim($raw));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ($scheme !== 'https' && !in_array($host, self::LOCAL_HOSTS, true)) {
            return null;
        }
        $port = isset($parts['port']) && $parts['port'] !== self::DEFAULT_PORTS[$scheme] ? ":{$parts['port']}" : '';

        return "{$scheme}://{$host}{$port}";
    }

    /** @return list<string> */
    private static function clearFields(mixed $option, ?string $raw): array
    {
        $wanted = Text::list($option) ?? Text::list($raw);
        if ($wanted === null) {
            return ['status'];
        }

        return array_values(array_filter(self::CLEAR_FIELDS, static fn (string $field) => in_array($field, $wanted, true)));
    }
}
