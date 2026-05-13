<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\ListOutboxRows;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\UserTenantRole;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;

/**
 * Read use case used by the backstage outbox table + API
 * `GET /api/v1/backstage/communications/outbox`.
 *
 * Authorisation:
 *   - GSA: may list any tenant's outbox.
 *   - Admin / moderator in the requested tenant: may list that tenant only.
 *   - Everyone else: {@see ForbiddenException}.
 */
final class ListOutboxRows
{
    public function __construct(
        private readonly MailOutboxRepositoryInterface $outboxRepo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$this->isAuthorized($acting, $input)) {
            throw new ForbiddenException();
        }

        $filters = [];
        if ($input->status !== null) {
            $filters['status'] = $input->status;
        }
        if ($input->kind !== null) {
            $filters['kind'] = $input->kind;
        }
        if ($input->from !== null) {
            $filters['from'] = $input->from;
        }
        if ($input->to !== null) {
            $filters['to'] = $input->to;
        }
        if ($input->recipientSubstring !== null && $input->recipientSubstring !== '') {
            $filters['recipient_substring'] = $input->recipientSubstring;
        }

        $rows  = $this->outboxRepo->listForTenant($input->tenantId, $filters, $input->page, $input->perPage);
        $total = $this->outboxRepo->countForTenant($input->tenantId, $filters);

        return new Output($rows, $total, $input->page, $input->perPage);
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
