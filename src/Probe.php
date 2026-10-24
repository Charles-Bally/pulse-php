<?php

declare(strict_types=1);

namespace Pulse;

final class Probe
{
    public const KINDS = ['database', 'redis', 'cache', 'queue', 'http', 'custom'];
    private const HTTP_TIMEOUT_SECONDS = 5;

    private readonly \Closure $run;

    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        callable $run,
        public readonly ?string $engine = null,
        private readonly ?\Closure $onFailure = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("type de contrôle inconnu : {$kind}");
        }
        $this->run = \Closure::fromCallable($run);
    }

    public function run(): mixed
    {
        return ($this->run)();
    }

    public function failed(): void
    {
        try {
            if ($this->onFailure !== null) {
                ($this->onFailure)();
            }
        } catch (\Throwable) {
        }
    }

    public function named(string $name): self
    {
        return new self($name, $this->kind, $this->run, $this->engine, $this->onFailure);
    }

    public static function http(string $name, string $url): self
    {
        return new self($name, 'http', static function () use ($url): void {
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]]);
            $body = @file_get_contents($url, false, $context, 0, 1);
            $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : @$http_response_header;
            if (!is_array($headers) || preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($headers[0] ?? ''), $match) !== 1) {
                throw new \RuntimeException($body === false ? (error_get_last()['message'] ?? 'pas de réponse') : 'réponse illisible');
            }
            if ((int) $match[1] >= 500) {
                throw new \RuntimeException("HTTP {$match[1]}");
            }
        });
    }
}
