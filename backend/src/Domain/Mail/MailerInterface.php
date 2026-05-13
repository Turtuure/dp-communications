<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Domain\Mail;

use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

interface MailerInterface
{
    /**
     * Send a mail outbox row using the supplied tenant settings.
     *
     * @throws Exception\MailerHardBounceException SMTP 5xx (suppression-trigger)
     * @throws Exception\MailerSoftBounceException SMTP 4xx (retry-trigger)
     * @throws Exception\MailerTransportException  Other transport errors
     * @throws Exception\SmtpNotConfigured         When tenant has no DSN configured
     */
    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void;
}
