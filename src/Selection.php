<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Logs\LogLevel;
use Pulse\Support\Text;

final class Selection
{
    public const SEND_FIELDS = ['checks', 'crons', 'logs', 'versions'];

    /**
     * @param list<string> $send
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public function __construct(
        public readonly array $send = self::SEND_FIELDS,
        public readonly array $include = [],
        public readonly array $exclude = [],
        public readonly string $logLevel = 'DEBUG',
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $env
     */
    public static function resolve(array $options, array $env): self
    {
        $wanted = Text::list($options['send'] ?? null) ?? Text::list($env['PULSE_SEND'] ?? null) ?? self::SEND_FIELDS;
        $logsOption = Text::bool($options['logs'] ?? null);
        $logsOff = $logsOption === false || ($logsOption === null && ($env['PULSE_LOGS'] ?? null) === 'false');
        $send = array_values(array_filter(
            self::SEND_FIELDS,
            static fn (string $field) => in_array($field, $wanted, true) && !($field === 'logs' && $logsOff),
        ));

        return new self(
            $send,
            Text::list($options['include'] ?? null) ?? Text::list($env['PULSE_INCLUDE'] ?? null) ?? [],
            Text::list($options['exclude'] ?? null) ?? Text::list($env['PULSE_EXCLUDE'] ?? null) ?? [],
            LogLevel::normalize(self::string($options['log_level'] ?? null) ?? $env['PULSE_LOG_LEVEL'] ?? null),
        );
    }

    public function wants(string $field): bool
    {
        return in_array($field, $this->send, true);
    }

    public function allows(string $name): bool
    {
        $included = $this->include === [] || self::matchesAny($name, $this->include);

        return $included && !self::matchesAny($name, $this->exclude);
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = implode('.*', array_map(static fn (string $part) => preg_quote($part, '/'), explode('*', $pattern)));
            if (preg_match("/^{$regex}$/iu", $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
