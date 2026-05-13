<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Crypto;

/**
 * Thrown when a ciphertext passed to decrypt() is not valid base64 or is
 * shorter than the required nonce length, meaning it cannot possibly be a
 * value produced by DsnEncryptor::encrypt().
 */
final class MalformedCiphertext extends \RuntimeException
{
}
