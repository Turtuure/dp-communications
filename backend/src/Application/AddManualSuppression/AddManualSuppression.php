<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\AddManualSuppression;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;

/**
 * Adds an email address to the tenant's suppression list (Wave G Task G1).
 *
 * Auth: tenant admin (NOT GSA-only — tenant admins manage their own
 * suppression list; platform admins typically don't touch tenant data
 * unless impersonating).
 *
 * The MailSuppression value object enforces RFC-822 email validation
 * (`filter_var(FILTER_VALIDATE_EMAIL)`), so invalid emails surface as
 * `\InvalidArgumentException` from the constructor → mapped to 400 by
 * the Kernel.
 *
 * Default reason is {@see SuppressionReason::ManualBlock}; the caller may
 * pass other reasons (e.g. when importing a known-bad-recipient list).
 */
final class AddManualSuppression
{
    public function __construct(
        private readonly MailSuppressionRepositoryInterface $repo,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $this->repo->add(new MailSuppression(
            tenantId:         $input->tenantId,
            emailAddress:     $input->emailAddress,
            reason:           $input->reason,
            suppressedAt:     $this->clock->now(),
            smtpResponseCode: null,
            suppressedBy:     $acting->id,
        ));

        return new Output(success: true);
    }
}
