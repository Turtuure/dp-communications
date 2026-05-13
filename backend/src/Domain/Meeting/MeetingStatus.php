<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Meeting;

enum MeetingStatus: string
{
    case Draft       = 'draft';
    case Scheduled   = 'scheduled';
    case InvitesSent = 'invites_sent';
    case Completed   = 'completed';
}
