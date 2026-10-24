<?php

declare(strict_types=1);

namespace Pulse\Http;

use Pulse\Config;
use Pulse\Support\Text;

final class Api
{
    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly string $userAgent,
    ) {
    }

    public static function transportFor(Config $config): Transport
    {
        if ($config->fingerprint !== null || !function_exists('curl_init')) {
            return new StreamTransport($config->fingerprint);
        }

        return new CurlTransport();
    }

    public function post(string $path, mixed $body): Response
    {
        return $this->transport->post($this->config->endpoint . $path, [
            'content-type' => 'application/json',
            'x-pulse-key' => $this->config->key,
            'user-agent' => $this->userAgent,
        ], Text::json($body));
    }
}
