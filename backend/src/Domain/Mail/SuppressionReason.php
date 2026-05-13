<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

enum SuppressionReason: string
{
    case HardBounce  = 'hard_bounce';
    case Complaint   = 'complaint';
    case ManualBlock = 'manual_block';
}
