<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail\Exception;

use DaemsModule\Communications\Domain\Mail\MailKind;

final class UnknownTemplateVarException extends \RuntimeException
{
    public function __construct(
        public readonly string $varName,
        public readonly MailKind $kind,
    ) {
        parent::__construct(
            sprintf("Unknown template var '%s' for kind %s", $varName, $kind->value),
        );
    }
}
