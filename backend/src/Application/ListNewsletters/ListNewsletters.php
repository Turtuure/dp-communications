<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListNewsletters;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRole;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;

/**
 * Lists all newsletter drafts (and sent newsletters) for a tenant.
 *
 * Auth: admin or moderator (moderators may inspect newsletter pipelines but
 * cannot send — see {@see \DaemsModule\Communications\Application\SendNewsletter}).
 *
 * Wave E Task E3 (Milestone 0.8 / communications-v1).
 */
final class ListNewsletters
{
    public function __construct(
        private readonly NewsletterDraftRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$this->isAuthorized($acting, $input)) {
            throw new ForbiddenException();
        }

        return new Output(newsletters: $this->repo->listForTenant($input->tenantId));
    }

    private function isAuthorized(ActingUser $acting, Input $input): bool
    {
        if ($acting->isPlatformAdmin()) {
            return true;
        }
        $role = $acting->roleIn($input->tenantId);
        return $role === UserTenantRole::Admin || $role === UserTenantRole::Moderator;
    }
}
