<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Crypto;

/**
 * Encrypts and decrypts per-tenant SMTP DSN strings using libsodium's
 * authenticated `crypto_secretbox` primitive (XSalsa20 + Poly1305).
 *
 * Storage format (after base64 encoding): nonce (24 bytes) || ciphertext.
 *
 * The key is supplied as a base64-encoded string at construction time
 * (typically read from APP_ENCRYPTION_KEY) and validated lazily on the
 * first encrypt() / decrypt() call.
 */
final class DsnEncryptor
{
    public function __construct(private readonly string $base64Key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $key = $this->loadKey();
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new MalformedCiphertext('Ciphertext is not valid base64 or is too short.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new DecryptionFailed('Authenticated decryption failed: wrong key or tampered ciphertext.');
        }

        return $plaintext;
    }

    private function loadKey(): string
    {
        $key = base64_decode($this->base64Key, true);

        if ($key === false) {
            throw new InvalidEncryptionKey('Encryption key is not valid base64.');
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidEncryptionKey(sprintf(
                'Encryption key must decode to %d bytes, got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key),
            ));
        }

        return $key;
    }
}
