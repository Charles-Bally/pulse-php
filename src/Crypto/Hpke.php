<?php

declare(strict_types=1);

namespace Pulse\Crypto;

final class Hpke
{
    public const AEADS = [
        'aes-128-gcm' => ['id' => 0x0001, 'keyBytes' => 16],
        'aes-256-gcm' => ['id' => 0x0002, 'keyBytes' => 32],
    ];
    private const HASH_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const KEM_ID = 0x0020;
    private const KDF_ID = 0x0001;

    /** @return array{privateKey: string, publicKey: string} */
    public static function generateKeyPair(): array
    {
        $private = random_bytes(32);

        return ['privateKey' => $private, 'publicKey' => sodium_crypto_scalarmult_base($private)];
    }

    public static function publicKeyOf(string $privateKey): string
    {
        return sodium_crypto_scalarmult_base($privateKey);
    }

    // RFC 9180, mode de base, un seul message : le numéro de séquence 0 garde le nonce de base tel quel.
    /** @return array{enc: string, ct: string} */
    public static function seal(
        string $recipientPublicKey,
        string $info,
        string $aad,
        string $plaintext,
        string $aead = 'aes-256-gcm',
        ?string $ephemeralPrivateKey = null,
    ): array {
        $ephemeral = $ephemeralPrivateKey ?? random_bytes(32);
        $enc = sodium_crypto_scalarmult_base($ephemeral);
        $dh = sodium_crypto_scalarmult($ephemeral, $recipientPublicKey);
        [$key, $nonce] = self::keySchedule(self::sharedSecret($dh, $enc, $recipientPublicKey), $info, $aead);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, $aead, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('chiffrement impossible');
        }

        return ['enc' => $enc, 'ct' => $ciphertext . $tag];
    }

    public static function open(
        string $recipientPrivateKey,
        string $info,
        string $aad,
        string $enc,
        string $ct,
        string $aead = 'aes-256-gcm',
    ): string {
        $recipient = sodium_crypto_scalarmult_base($recipientPrivateKey);
        $dh = sodium_crypto_scalarmult($recipientPrivateKey, $enc);
        [$key, $nonce] = self::keySchedule(self::sharedSecret($dh, $enc, $recipient), $info, $aead);
        $tag = substr($ct, -self::TAG_BYTES);
        $plaintext = openssl_decrypt(substr($ct, 0, -self::TAG_BYTES), $aead, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plaintext === false) {
            throw new \RuntimeException('déchiffrement impossible');
        }

        return $plaintext;
    }

    private static function sharedSecret(string $dh, string $enc, string $recipient): string
    {
        $suite = 'KEM' . self::i2osp(self::KEM_ID, 2);
        $prk = self::labeledExtract($suite, '', 'eae_prk', $dh);

        return self::labeledExpand($suite, $prk, 'shared_secret', $enc . $recipient, self::HASH_BYTES);
    }

    /** @return array{0: string, 1: string} */
    private static function keySchedule(string $shared, string $info, string $aead): array
    {
        $settings = self::AEADS[$aead] ?? throw new \InvalidArgumentException("AEAD inconnu : {$aead}");
        $suite = 'HPKE' . self::i2osp(self::KEM_ID, 2) . self::i2osp(self::KDF_ID, 2) . self::i2osp($settings['id'], 2);
        $context = self::i2osp(0, 1)
            . self::labeledExtract($suite, '', 'psk_id_hash', '')
            . self::labeledExtract($suite, '', 'info_hash', $info);
        $secret = self::labeledExtract($suite, $shared, 'secret', '');

        return [
            self::labeledExpand($suite, $secret, 'key', $context, $settings['keyBytes']),
            self::labeledExpand($suite, $secret, 'base_nonce', $context, self::NONCE_BYTES),
        ];
    }

    private static function labeledExtract(string $suite, string $salt, string $label, string $ikm): string
    {
        return self::extract($salt, 'HPKE-v1' . $suite . $label . $ikm);
    }

    private static function labeledExpand(string $suite, string $prk, string $label, string $info, int $length): string
    {
        return self::expand($prk, self::i2osp($length, 2) . 'HPKE-v1' . $suite . $label . $info, $length);
    }

    private static function extract(string $salt, string $ikm): string
    {
        return hash_hmac('sha256', $ikm, $salt === '' ? str_repeat("\0", self::HASH_BYTES) : $salt, true);
    }

    private static function expand(string $prk, string $info, int $length): string
    {
        $out = '';
        $previous = '';
        for ($counter = 1; strlen($out) < $length; $counter++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($counter), $prk, true);
            $out .= $previous;
        }

        return substr($out, 0, $length);
    }

    private static function i2osp(int $value, int $bytes): string
    {
        return substr(pack('J', $value), -$bytes);
    }
}
