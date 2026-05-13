<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Template;

enum NewsletterStatus: string
{
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Sent      = 'sent';
}
