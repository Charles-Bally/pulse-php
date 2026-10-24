<?php

declare(strict_types=1);

namespace Pulse\Http;

interface Transport
{
    public const TIMEOUT_SECONDS = 10;

    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body): Response;
}
