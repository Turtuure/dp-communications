<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Crypto;

/**
 * Thrown when the configured encryption key is not valid base64 or does not
 * decode to exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32) bytes.
 */
final class InvalidEncryptionKey extends \RuntimeException
{
}
