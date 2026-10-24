<?php

declare(strict_types=1);

namespace Pulse;

interface Logger
{
    public function warn(string $message): void;

    public function error(string $message): void;
}
