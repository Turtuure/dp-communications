<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Crypto;

/**
 * Thrown when sodium_crypto_secretbox_open() returns false — either the
 * ciphertext was tampered with or the key does not match the one used to
 * encrypt the value.
 */
final class DecryptionFailed extends \RuntimeException
{
}
