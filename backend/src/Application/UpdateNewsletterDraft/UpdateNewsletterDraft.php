<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\UpdateNewsletterDraft;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;

/**
 * Updates an editable newsletter draft. A `Sent` newsletter is immutable — any
 * attempt to update one throws a `DomainException`.
 *
 * Re-saves the entire `subjectByLocale` + `blocksByLocale` map so callers must
 * send the full state (locale-cards UI pattern, mirrors existing i18n editors).
 *
 * Auth: tenant Admin only.
 *
 * Wave E Task E3 (Milestone 0.8 / communications-v1).
 */
final class UpdateNewsletterDraft
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
            throw new \DomainException('Sent newsletter cannot be updated');
        }

        $internalName = $input->internalName !== null && trim($input->internalName) !== ''
            ? $input->internalName
            : $existing->internalName;

        $updated = new NewsletterDraft(
            id:               $existing->id,
            tenantId:         $existing->tenantId,
            internalName:     $internalName,
            subjectByLocale:  $input->subjectByLocale,
            blocksByLocale:   $input->blocksByLocale,
            audience:         $input->audience,
            status:           $existing->status,
            sentAt:           $existing->sentAt,
            createdAt:        $existing->createdAt,
            createdBy:        $existing->createdBy,
        );

        $this->repo->save($updated);

        return new Output(newsletterId: $updated->id);
    }
}
