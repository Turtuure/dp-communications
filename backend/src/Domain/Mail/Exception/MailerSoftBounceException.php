<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail\Exception;

final class MailerSoftBounceException extends \RuntimeException
{
    public function __construct(
        public readonly string $smtpCode,
        string $message,
        int $code = 0,
        ?\Throwable $prev = null,
    ) {
        parent::__construct($message, $code, $prev);
    }
}
