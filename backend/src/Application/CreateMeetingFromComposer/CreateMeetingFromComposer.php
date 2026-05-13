<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\CreateMeetingFromComposer;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\Clock;
use DaemsModule\Communications\Domain\Meeting\Meeting;
use DaemsModule\Communications\Domain\Meeting\MeetingId;
use DaemsModule\Communications\Domain\Meeting\MeetingRepositoryInterface;
use DaemsModule\Communications\Domain\Meeting\MeetingStatus;

/**
 * Creates a Meeting row from the backstage composer "uusi kokouskutsu" flow.
 *
 * Wave D Task D5.
 *
 * Authorisation:
 *   - Only admins (or platform admins) — moderators may compose messages but
 *     cannot create governance artefacts like meetings.
 *
 * Note: the 14-day notice rule from § 6 of the model bylaws lands in
 * milestone 0.9 (governance). For now we silently allow past-dated meetings
 * — used by tests that re-invite to an already-held meeting.
 */
final class CreateMeetingFromComposer
{
    public function __construct(
        private readonly MeetingRepositoryInterface $meetingRepo,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $meeting = new Meeting(
            id:                  MeetingId::generate(),
            tenantId:            $input->tenantId,
            type:                $input->type,
            titleByLocale:       $input->titleByLocale,
            startsAt:            $input->startsAt,
            location:            $input->location,
            remoteUrl:           $input->remoteUrl,
            agendaItemsByLocale: $input->agendaItemsByLocale,
            documentUrls:        $input->documentUrls,
            status:              MeetingStatus::Scheduled,
            createdAt:           $this->clock->now(),
            createdBy:           $acting->id,
        );
        $this->meetingRepo->save($meeting);

        return new Output(meetingId: $meeting->id);
    }
}
