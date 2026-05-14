<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\RemoveSuppression;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;

/**
 * Removes an email address from the tenant's suppression list (Wave G Task G1).
 *
 * Auth: tenant admin. Idempotent — removing an email that isn't on the list
 * is a no-op (the repository's `remove` swallows the no-such-row case).
 */
final class RemoveSuppression
{
    public function __construct(
        private readonly MailSuppressionRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $this->repo->remove($input->tenantId, $input->emailAddress);

        return new Output(success: true);
    }
}
