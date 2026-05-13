<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Crypto;

use DaemsModule\Communications\Infrastructure\Crypto\DecryptionFailed;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Crypto\InvalidEncryptionKey;
use PHPUnit\Framework\TestCase;

final class DsnEncryptorTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $encryptor = new DsnEncryptor($this->key);
        $plaintext = 'smtp://user:pass@smtp.example.com:587?encryption=tls';

        $ciphertext = $encryptor->encrypt($plaintext);
        $decrypted = $encryptor->decrypt($ciphertext);

        self::assertSame($plaintext, $decrypted);
        self::assertNotSame($plaintext, $ciphertext);
    }

    public function test_different_nonces_produce_different_ciphertexts(): void
    {
        $encryptor = new DsnEncryptor($this->key);
        $plaintext = 'smtp://user:pass@smtp.example.com:587';

        $first = $encryptor->encrypt($plaintext);
        $second = $encryptor->encrypt($plaintext);

        self::assertNotSame($first, $second);
        self::assertSame($plaintext, $encryptor->decrypt($first));
        self::assertSame($plaintext, $encryptor->decrypt($second));
    }

    public function test_wrong_key_throws(): void
    {
        $encryptorA = new DsnEncryptor($this->key);
        $otherKey = base64_encode(sodium_crypto_secretbox_keygen());
        $encryptorB = new DsnEncryptor($otherKey);

        $ciphertext = $encryptorA->encrypt('smtp://user:pass@smtp.example.com:587');

        $this->expectException(DecryptionFailed::class);
        $encryptorB->decrypt($ciphertext);
    }

    public function test_malformed_key_throws(): void
    {
        $encryptor = new DsnEncryptor('not-a-valid-base64-key-!!!');

        $this->expectException(InvalidEncryptionKey::class);
        $encryptor->encrypt('smtp://user:pass@smtp.example.com:587');
    }
}
