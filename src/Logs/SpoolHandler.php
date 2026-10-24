<?php

declare(strict_types=1);

namespace Pulse\Logs;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Pulse\Storage\StateStore;

final class SpoolHandler extends AbstractHandler
{
    private static bool $muted = false;
    private static bool $writing = false;

    public function __construct(
        private readonly ?LogSpool $spool,
        private readonly ?StateStore $state,
        private readonly string $minimum = 'DEBUG',
    ) {
        parent::__construct(Level::Debug, true);
    }

    public static function off(): self
    {
        return new self(null, null);
    }

    public static function in(string $directory, string $minimum = 'DEBUG'): self
    {
        return new self(LogSpool::in($directory), StateStore::in($directory), LogLevel::normalize($minimum));
    }

    // Le worker coupe la recopie dans son propre processus : les messages de Pulse ne reviennent pas vers Pulse.
    public static function mute(bool $muted = true): void
    {
        self::$muted = $muted;
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->spool !== null
            && $this->state !== null
            && !self::$muted
            && !self::$writing
            && ($record->context['pulse'] ?? null) !== true
            && LogLevel::atLeast(LogLevel::fromFramework($record->level->getName()), $this->minimum)
            && $this->state->logsOn();
    }

    // Une ligne illisible ne doit jamais casser l'écriture de l'app : rien ne sort d'ici.
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }
        self::$writing = true;
        try {
            $this->spool?->append(LogEntry::make(
                LogLevel::fromFramework($record->level->getName()),
                self::message($record),
                $record->channel,
                $record->datetime,
            ));
        } catch (\Throwable) {
        } finally {
            self::$writing = false;
        }

        return false;
    }

    private static function message(LogRecord $record): string
    {
        $message = $record->message;
        if (str_contains($message, '{')) {
            $message = preg_replace_callback('/\{([A-Za-z0-9_.-]+)\}/', static function (array $match) use ($record) {
                $value = $record->context[$match[1]] ?? null;

                return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $match[0];
            }, $message) ?? $message;
        }
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof \Throwable && !str_contains($message, $exception->getMessage())) {
            $message .= "\n" . $exception::class . ': ' . $exception->getMessage();
        }
        if ($exception instanceof \Throwable) {
            $message .= ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ')';
        }

        return $message;
    }
}
