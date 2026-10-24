<?php

declare(strict_types=1);

namespace Pulse\Http;

final class StreamTransport implements Transport
{
    public function __construct(private readonly ?string $fingerprint = null)
    {
    }

    public function post(string $url, array $headers, string $body): Response
    {
        $parts = parse_url($url);
        $https = ($parts['scheme'] ?? '') === 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($https ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
        $socket = $this->connect($https, $host, $port);
        try {
            if ($https && $this->fingerprint !== null) {
                $this->checkPin($socket);
            }
            $request = "POST {$path} HTTP/1.1\r\nHost: {$host}" . (isset($parts['port']) ? ":{$port}" : '') . "\r\n";
            foreach ($headers as $name => $value) {
                $request .= "{$name}: {$value}\r\n";
            }
            $request .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            if (@fwrite($socket, $request) === false) {
                throw new TransportException('envoi interrompu');
            }

            return $this->read($socket);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect(bool $https, string $host, int $port)
    {
        $ssl = $this->fingerprint !== null
            ? ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'capture_peer_cert' => true]
            : ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => trim($host, '[]')];
        $context = stream_context_create(['ssl' => $ssl]);
        $remote = ($https ? 'tls' : 'tcp') . "://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new TransportException($errstr !== '' ? $errstr : 'connexion impossible');
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        return $socket;
    }

    // Un relais sans domaine a un certificat qu'il a signé lui-même : on vérifie son empreinte avant d'envoyer quoi que ce soit.
    /** @param resource $socket */
    private function checkPin($socket): void
    {
        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        $digest = $certificate === null ? false : openssl_x509_fingerprint($certificate, 'sha256');
        $seen = $digest === false ? '' : strtoupper(implode(':', str_split($digest, 2)));
        if (!hash_equals((string) $this->fingerprint, $seen)) {
            throw new TransportException('certificat du relais inattendu : vérifie PULSE_RELAY_FINGERPRINT');
        }
    }

    /** @param resource $socket */
    private function read($socket): Response
    {
        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
                throw new TransportException('pas de réponse dans le délai');
            }
            $raw .= $chunk;
        }
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $match) !== 1) {
            throw new TransportException('réponse illisible');
        }
        if (preg_match('/^transfer-encoding:\s*chunked/im', $head) === 1) {
            $body = self::dechunk($body);
        }

        return new Response((int) $match[1], $body);
    }

    private static function dechunk(string $body): string
    {
        $out = '';
        while ($body !== '') {
            [$size, $rest] = array_pad(explode("\r\n", $body, 2), 2, '');
            $length = (int) hexdec(trim(explode(';', $size)[0]));
            if ($length === 0) {
                break;
            }
            $out .= substr($rest, 0, $length);
            $body = substr($rest, $length + 2);
        }

        return $out;
    }
}
