<?php

declare(strict_types=1);

namespace Pulse\Support;

final class Text
{
    // L'API compte en unités UTF-16 comme JavaScript : un caractère hors du plan de base en vaut deux.
    public static function cut(string $text, int $units): string
    {
        $text = self::scrub($text);
        if (strlen($text) <= $units) {
            return $text;
        }
        preg_match_all('/./su', $text, $matches);
        $out = '';
        $count = 0;
        foreach ($matches[0] as $char) {
            $width = strlen($char) === 4 ? 2 : 1;
            if ($count + $width > $units) {
                break;
            }
            $out .= $char;
            $count += $width;
        }

        return $out;
    }

    public static function scrub(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        if (function_exists('mb_scrub')) {
            return mb_scrub($text, 'UTF-8');
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $clean === false ? '' : $clean;
    }

    public static function iso(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<string>|null */
    public static function list(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function bool(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $value = strtolower(trim((string) $raw));
        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        return in_array($value, ['false', '0', 'no', 'off'], true) ? false : null;
    }
}
