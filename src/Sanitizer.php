<?php

declare(strict_types=1);

namespace Pulse;

use Pulse\Support\Text;

final class Sanitizer
{
    public const MAX_ERROR_LENGTH = 300;

    // Identifiants d'URL, jetons et mots de passe ne partent jamais, même au détour d'un message.
    public static function redactSecrets(string $text): string
    {
        $text = Text::scrub($text);
        $rules = [
            '/([a-z][a-z0-9+.-]*:\/\/)[^\s\/@]*@/iu' => '$1***@',
            '/(password|passwd|pwd|secret|token|key)=\S+/iu' => '$1=***',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/u' => '$1 ***',
            '/\bpk_(live|test|agent)_[A-Za-z0-9_-]+/u' => 'pk_$1_***',
        ];
        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    public static function error(mixed $error): string
    {
        $code = '';
        if ($error instanceof \Throwable) {
            $raw = $error->getCode();
            $code = $raw === 0 || $raw === '' ? '' : (string) $raw;
            $message = $error->getMessage();
        } else {
            $message = is_scalar($error) || $error instanceof \Stringable ? (string) $error : '';
        }
        $cleaned = self::redactSecrets($message);
        $cleaned = trim(preg_replace('/(https?:\/\/[^\s?#]+)[?#][^\s]*/iu', '$1', $cleaned) ?? $cleaned);
        $text = $code !== '' && !str_contains($cleaned, $code) ? trim("{$code} {$cleaned}") : $cleaned;

        return Text::cut($text === '' ? 'erreur inconnue' : $text, self::MAX_ERROR_LENGTH);
    }
}
