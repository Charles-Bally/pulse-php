<?php

declare(strict_types=1);

namespace Pulse\Crypto;

use Pulse\Support\Text;

final class Sealer
{
    public const PURPOSES = ['report', 'logs'];
    private const PUBLIC_KEY_PATTERN = '/^pulse_pub_([A-Za-z0-9_-]{43})$/';
    private const KEY_ID_HEX_LENGTH = 16;
    private const INFO = 'pulse/v1';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_scalarmult')
            && function_exists('openssl_encrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    public static function parsePublicKey(?string $text): ?string
    {
        if (preg_match(self::PUBLIC_KEY_PATTERN, trim((string) $text), $match) !== 1) {
            return null;
        }
        $raw = self::fromBase64Url($match[1]);

        return strlen($raw) === 32 ? $raw : null;
    }

    public static function formatPublicKey(string $raw): string
    {
        return 'pulse_pub_' . self::toBase64Url($raw);
    }

    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, self::KEY_ID_HEX_LENGTH);
    }

    /** @return array{v: int, kid: string, enc: string, ct: string} */
    public static function seal(string $publicKey, string $purpose, mixed $value): array
    {
        $kid = self::keyId($publicKey);
        $sealed = Hpke::seal($publicKey, self::INFO, self::aad($purpose, $kid), Text::json($value));

        return ['v' => 1, 'kid' => $kid, 'enc' => self::toBase64Url($sealed['enc']), 'ct' => self::toBase64Url($sealed['ct'])];
    }

    /** @param array{enc: string, ct: string} $envelope */
    public static function open(string $privateKey, string $publicKey, string $purpose, array $envelope): mixed
    {
        $plaintext = Hpke::open(
            $privateKey,
            self::INFO,
            self::aad($purpose, self::keyId($publicKey)),
            self::fromBase64Url($envelope['enc']),
            self::fromBase64Url($envelope['ct']),
        );

        return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
    }

    // L'usage entre dans les données authentifiées : un rapport scellé ne peut pas passer pour des journaux.
    private static function aad(string $purpose, string $kid): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException("usage inconnu : {$purpose}");
        }

        return "pulse/{$purpose}/{$kid}";
    }

    private static function toBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function fromBase64Url(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), false);
    }
}
