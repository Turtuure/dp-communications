<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\DeleteNewsletterDraft;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;

/**
 * Deletes a newsletter draft. Sent newsletters are retained as records and
 * cannot be deleted via this use case — attempting so raises a
 * {@see \DomainException}.
 *
 * Auth: tenant Admin only.
 *
 * Wave E Task E3 (Milestone 0.8 / communications-v1).
 */
final class DeleteNewsletterDraft
{
    public function __construct(
        private readonly NewsletterDraftRepositoryInterface $repo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $existing = $this->repo->findById($input->newsletterId);
        if ($existing === null) {
            throw new \DomainException('Newsletter not found');
        }
        if (!$existing->tenantId->equals($input->tenantId)) {
            throw new ForbiddenException();
        }
        if ($existing->status === NewsletterStatus::Sent) {
            throw new \DomainException('Sent newsletter cannot be deleted');
        }

        $this->repo->delete($input->newsletterId);

        return new Output(deleted: true);
    }
}
