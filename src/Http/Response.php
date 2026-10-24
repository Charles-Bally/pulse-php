<?php

declare(strict_types=1);

namespace Pulse\Http;

final class Response
{
    public function __construct(public readonly int $status, public readonly string $body = '')
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
