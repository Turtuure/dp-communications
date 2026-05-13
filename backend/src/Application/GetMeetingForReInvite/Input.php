<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetMeetingForReInvite;

use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Meeting\MeetingId;

final class Input
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly MeetingId $meetingId,
    ) {
    }
}
