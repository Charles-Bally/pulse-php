<?php

declare(strict_types=1);

namespace Pulse;

final class Env
{
    /** @return array<string, string> */
    public static function current(): array
    {
        $env = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && is_string($value) && str_starts_with($name, 'PULSE_')) {
                    $env[$name] = $value;
                }
            }
        }

        return $env;
    }
}
