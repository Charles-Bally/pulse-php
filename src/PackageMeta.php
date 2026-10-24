<?php

declare(strict_types=1);

namespace Pulse;

final class PackageMeta
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $packageVersion,
        public readonly string $framework,
        public readonly ?string $frameworkVersion = null,
    ) {
    }

    public function userAgent(): string
    {
        return "{$this->packageName}/{$this->packageVersion}";
    }
}
