<?php

declare(strict_types=1);

namespace Pulse\Http;

final class CurlTransport implements Transport
{
    public function post(string $url, array $headers, string $body): Response
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('connexion impossible');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_NOSIGNAL => true,
        ]);
        $output = curl_exec($handle);
        if (!is_string($output)) {
            throw new TransportException(curl_error($handle) ?: 'pas de réponse');
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $output);
    }
}
