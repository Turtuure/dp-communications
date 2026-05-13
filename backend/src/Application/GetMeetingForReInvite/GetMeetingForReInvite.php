<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetMeetingForReInvite;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\UserTenantRole;
use DaemsModule\Communications\Domain\Meeting\MeetingRepositoryInterface;

/**
 * Read-side use case for the composer "Kutsu uudelleen" flow — fetches a
 * previously-created Meeting so the composer UI can pre-fill the form with
 * its title / startsAt / location / agenda before queueing a fresh batch of
 * invitations to (potentially) a different audience.
 *
 * Wave D Task D5.
 *
 * Authorisation:
 *   - admin or moderator in the meeting's tenant, OR platform admin.
 *   - Tenant scoping is enforced: if {@see Input::$tenantId} doesn't match the
 *     meeting's tenant we 404 (NOT 403) to avoid leaking existence.
 */
final class GetMeetingForReInvite
{
    public function __construct(
        private readonly MeetingRepositoryInterface $meetingRepo,
    ) {
    }

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$this->isAuthorized($acting, $input)) {
            throw new ForbiddenException();
        }

        $meeting = $this->meetingRepo->findById($input->meetingId);
        if ($meeting === null || !$meeting->tenantId->equals($input->tenantId)) {
            throw new NotFoundException('meeting_not_found');
        }

        return new Output(meeting: $meeting);
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
