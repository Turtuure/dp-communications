<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Mailer;

use DaemsModule\Communications\Domain\Mail\Exception\MailerHardBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerSoftBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Production adapter for {@see MailerInterface} backed by Symfony Mailer 6.4.
 *
 * The per-tenant SMTP DSN is decrypted on every send so a key rotation only
 * needs to update the ciphertext in tenant_communication_settings — the
 * adapter itself is stateless.
 *
 * SMTP-level errors are mapped to domain exceptions by parsing the SMTP
 * response code out of the transport's exception message. The parser
 * understands both extended status codes (RFC 3463, e.g. 5.1.1) and basic
 * 3-digit codes; it returns null when neither pattern matches so the caller
 * can fall back to a generic transport error.
 */
final class SymfonyMailerAdapter implements MailerInterface
{
    public function __construct(private readonly DsnEncryptor $decryptor)
    {
    }

    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void
    {
        if (!$settings->isSmtpConfigured()) {
            throw new SmtpNotConfigured(sprintf(
                'Tenant %s has no SMTP DSN configured.',
                $row->tenantId->value(),
            ));
        }

        \assert($settings->smtpDsnEncrypted !== null);
        \assert($settings->mailFromAddress !== null);

        $dsn = $this->decryptor->decrypt($settings->smtpDsnEncrypted);
        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from(new Address($settings->mailFromAddress, $settings->mailDisplayName ?? ''))
            ->replyTo($settings->mailReplyTo ?? $settings->mailFromAddress)
            ->to($row->recipientEmail)
            ->subject($row->subject)
            ->html($row->bodyHtml)
            ->text($row->bodyText);

        try {
            $mailer->send($email);
        } catch (TransportException $e) {
            $code = $this->parseSmtpCode($e->getMessage());
            if ($code !== null && str_starts_with($code, '5')) {
                throw new MailerHardBounceException($code, $e->getMessage(), 0, $e);
            }
            if ($code !== null && str_starts_with($code, '4')) {
                throw new MailerSoftBounceException($code, $e->getMessage(), 0, $e);
            }
            throw new MailerTransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts the SMTP response code from a transport-error message.
     *
     * Recognizes:
     *   - Enhanced status codes (RFC 3463): "5.1.1", "4.2.2", "5.7.0", ...
     *   - Basic 3-digit codes as reported by Symfony: `code "550"` / `code "451"`.
     *
     * Enhanced codes win over basic ones because they carry more diagnostic
     * value (e.g. 5.1.1 = no such user vs. bare 550 = mailbox unavailable).
     */
    public function parseSmtpCode(string $message): ?string
    {
        // Enhanced status code format X.Y.Z preferred over basic 3-digit.
        if (preg_match('/\b([45]\.\d+\.\d+)\b/', $message, $m) === 1) {
            return $m[1];
        }
        // Fall back to basic 3-digit SMTP code if no enhanced code present.
        if (preg_match('/\bcode "([45]\d{2})"/', $message, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function isHardBounce(string $code): bool
    {
        return str_starts_with($code, '5');
    }
}
