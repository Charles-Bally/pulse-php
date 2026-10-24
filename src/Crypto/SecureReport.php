<?php

declare(strict_types=1);

namespace Pulse\Crypto;

final class SecureReport
{
    private const VERSION_FIELDS = ['packageName', 'packageVersion', 'framework', 'frameworkVersion', 'appVersion', 'deployedAt'];

    /**
     * @param array<string, mixed> $report
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function clearPart(array $report, array $fields): array
    {
        $clear = [];
        if (in_array('status', $fields, true)) {
            $problems = self::problems($report);
            $clear['status'] = ['ok' => $problems === 0, 'problems' => $problems];
        }
        if (in_array('checks', $fields, true)) {
            $clear['checks'] = $report['checks'] ?? [];
        }
        if (in_array('crons', $fields, true)) {
            $clear['crons'] = $report['crons'] ?? [];
        }
        if (in_array('versions', $fields, true)) {
            foreach (self::VERSION_FIELDS as $field) {
                if (isset($report[$field])) {
                    $clear[$field] = $report[$field];
                }
            }
        }

        return $clear;
    }

    /**
     * @param array<string, mixed> $report
     * @param list<string> $clear
     * @return array{sealed: array<string, mixed>, clear: array<string, mixed>|object}
     */
    public static function payload(array $report, string $publicKey, array $clear): array
    {
        $part = self::clearPart($report, $clear);

        return ['sealed' => Sealer::seal($publicKey, 'report', $report), 'clear' => $part === [] ? new \stdClass() : $part];
    }

    /** @param array<string, mixed> $report */
    private static function problems(array $report): int
    {
        $failedChecks = array_filter((array) ($report['checks'] ?? []), static fn (array $check) => $check['ok'] === false);
        $failedCrons = array_filter((array) ($report['crons'] ?? []), static fn (array $cron) => ($cron['lastRunOk'] ?? null) === false);

        return count($failedChecks) + count($failedCrons);
    }
}
