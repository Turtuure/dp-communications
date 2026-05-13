<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

enum MailOutboxStatus: string
{
    case Queued     = 'queued';
    case Sending    = 'sending';
    case Sent       = 'sent';
    case Failed     = 'failed';
    case Bounced    = 'bounced';
    case Suppressed = 'suppressed';
}
