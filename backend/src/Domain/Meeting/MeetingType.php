<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Meeting;

enum MeetingType: string
{
    case AnnualMeeting = 'annual_meeting';
    case Extraordinary = 'extraordinary';
    case BoardMeeting  = 'board_meeting';
}
