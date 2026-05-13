<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\GetCommunicationSettings;

use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

final class Output
{
    public function __construct(
        public readonly TenantCommunicationSettings $settings,
        /** True if smtpDsnEncrypted on the returned settings has been masked to `***` for a non-GSA caller. */
        public readonly bool $dsnMasked,
        /** True iff the underlying tenant settings have an actual SMTP DSN configured. */
        public readonly bool $dsnIsConfigured,
    ) {
    }
}
