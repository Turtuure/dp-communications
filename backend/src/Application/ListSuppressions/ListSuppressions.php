<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListSuppressions;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;

/**
 * Reads the per-tenant suppression list (Wave G Task G1).
 *
 * Auth: tenant admin OR GSA (platform admin). The list contains email
 * addresses and is treated as sensitive — only admins see it. Members
 * who want to verify their own opt-in state use the user-preference
 * endpoint instead.
 */
final class ListSuppressions
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

        return new Output($this->repo->listForTenant($input->tenantId));
    }
}
