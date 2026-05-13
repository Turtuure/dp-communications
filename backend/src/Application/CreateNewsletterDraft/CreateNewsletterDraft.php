<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\CreateNewsletterDraft;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use DaemsModule\Communications\Domain\Audience\AudienceFilter;
use DaemsModule\Communications\Domain\Mail\NewsletterId;
use DaemsModule\Communications\Domain\Template\NewsletterDraft;
use DaemsModule\Communications\Domain\Template\NewsletterDraftRepositoryInterface;
use DaemsModule\Communications\Domain\Template\NewsletterStatus;

/**
 * Creates an empty newsletter draft owned by the calling admin.
 *
 * Returns the assigned {@see NewsletterId} so the UI can redirect to the
 * editor page (`/backstage/communications/newsletters/{id}/edit`).
 *
 * Auth: tenant Admin only (newsletters are marketing-category content
 * and only admins may compose them — see § 5.3 of the plan).
 *
 * Wave E Task E3 (Milestone 0.8 / communications-v1).
 */
final class CreateNewsletterDraft
{
    public function __construct(
        private readonly NewsletterDraftRepositoryInterface $repo,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }
        if (trim($input->internalName) === '') {
            throw new \InvalidArgumentException('internalName cannot be empty');
        }

        $draft = new NewsletterDraft(
            id:               NewsletterId::generate(),
            tenantId:         $input->tenantId,
            internalName:     $input->internalName,
            subjectByLocale:  [],
            blocksByLocale:   [],
            audience:         new AudienceFilter([], [], null, []),
            status:           NewsletterStatus::Draft,
            sentAt:           null,
            createdAt:        $this->clock->now(),
            createdBy:        $acting->id,
        );

        $this->repo->save($draft);

        return new Output(newsletterId: $draft->id);
    }
}
