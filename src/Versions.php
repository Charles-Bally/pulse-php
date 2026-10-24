<?php

declare(strict_types=1);

namespace Pulse;

use Composer\InstalledVersions;
use Pulse\Support\Text;

final class Versions
{
    public const PULSE_PHP = '0.0.1';

    public static function of(string $package, ?string $fallback = null): ?string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                $version = InstalledVersions::getPrettyVersion($package);
                if (is_string($version) && preg_match('/^v?\d+\.\d+/', $version) === 1) {
                    return Text::cut(ltrim($version, 'v'), 30);
                }
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }
}
