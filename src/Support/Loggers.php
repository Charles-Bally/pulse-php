<?php

declare(strict_types=1);

namespace Pulse\Support;

use Pulse\Logger;

final class Loggers
{
    public static function stderr(): Logger
    {
        return new class implements Logger {
            public function warn(string $message): void
            {
                self::write($message);
            }

            public function error(string $message): void
            {
                self::write($message);
            }

            private static function write(string $message): void
            {
                @file_put_contents('php://stderr', "Pulse : {$message}\n");
            }
        };
    }

    public static function none(): Logger
    {
        return new class implements Logger {
            public function warn(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        };
    }

    public static function psr(object $logger): Logger
    {
        return new class ($logger) implements Logger {
            public function __construct(private readonly object $logger)
            {
            }

            public function warn(string $message): void
            {
                $this->call('warning', $message);
            }

            public function error(string $message): void
            {
                $this->call('error', $message);
            }

            private function call(string $method, string $message): void
            {
                try {
                    if (method_exists($this->logger, $method)) {
                        $this->logger->{$method}("Pulse : {$message}", ['pulse' => true]);
                    }
                } catch (\Throwable) {
                }
            }
        };
    }

    public static function both(Logger ...$loggers): Logger
    {
        return new class ($loggers) implements Logger {
            /** @param list<Logger> $loggers */
            public function __construct(private readonly array $loggers)
            {
            }

            public function warn(string $message): void
            {
                foreach ($this->loggers as $logger) {
                    $logger->warn($message);
                }
            }

            public function error(string $message): void
            {
                foreach ($this->loggers as $logger) {
                    $logger->error($message);
                }
            }
        };
    }
}
