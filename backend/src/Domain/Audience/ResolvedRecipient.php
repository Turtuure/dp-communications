<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Audience;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\User\UserId;

final class ResolvedRecipient
{
    /**
     * @param array<string, mixed> $contextVars per-recipient template variables (first_name, member_number, …)
     */
    public function __construct(
        public readonly UserId $userId,
        public readonly string $email,
        public readonly SupportedLocale $locale,
        public readonly string $firstName,
        public readonly array $contextVars,
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid recipient email: ' . $email);
        }
    }
}
