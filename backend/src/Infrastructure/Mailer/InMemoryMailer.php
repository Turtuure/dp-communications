<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Mailer;

use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

/**
 * Test adapter for {@see MailerInterface}.
 *
 * Captures every send() call into {@see $sent} so tests can assert on the
 * messages that would have been delivered. Setting {@see $simulateFailure}
 * makes the next send() throw that exception (and only that next send —
 * the field is auto-reset after the throw).
 */
final class InMemoryMailer implements MailerInterface
{
    /** @var list<array{row: MailOutbox, settings: TenantCommunicationSettings}> */
    public array $sent = [];

    public ?\Throwable $simulateFailure = null;

    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void
    {
        if ($this->simulateFailure !== null) {
            $failure = $this->simulateFailure;
            $this->simulateFailure = null;
            throw $failure;
        }

        $this->sent[] = ['row' => $row, 'settings' => $settings];
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->simulateFailure = null;
    }
}
