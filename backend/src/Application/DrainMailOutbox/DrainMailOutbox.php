<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Application\DrainMailOutbox;

use Daems\Domain\Auth\ActingUser;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\Input as MarkInput;
use DaemsModule\Communications\Application\MarkSuppressedRecipientsInPending\MarkSuppressedRecipientsInPending;
use DaemsModule\Communications\Domain\Mail\Exception\MailerHardBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerSoftBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;
use DaemsModule\Communications\Domain\Mail\MailSuppression;
use DaemsModule\Communications\Domain\Mail\MailSuppressionRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\SuppressionReason;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;

/**
 * Cron use case (spec § 5.2 + § 7.4): drain the next batch of queued mail
 * rows.
 *
 * Pipeline:
 *   1. Pre-step — {@see MarkSuppressedRecipientsInPending} flips any
 *      `queued` row whose recipient is on the tenant's suppression list to
 *      `suppressed` so it is never picked here.
 *   2. {@see MailOutboxRepositoryInterface::pickNextForSending()} atomically
 *      moves up to `batchSize` rows from `queued` → `sending` under a
 *      `FOR UPDATE SKIP LOCKED` so concurrent cron processes can run safely.
 *   3. For each picked row the configured {@see MailerInterface} is asked
 *      to send. SMTP outcomes are mapped to one of:
 *        - happy path           → status `Sent`, counted in `$sent`
 *        - hard bounce (5xx)    → recipient added to suppression list,
 *                                 status `Bounced`, counted in `$bounced`
 *        - soft bounce (4xx)    → attempt incremented; after 3 failed
 *                                 attempts the row is marked `Failed`
 *                                 (counted in `$failed`), otherwise it
 *                                 stays `Sending` with the error noted
 *                                 (counted in `$retried`)
 *        - transport / no-DSN   → status `Failed`, counted in `$failed`
 *
 * `$acting` is nullable because the use case runs from cron — no auth
 * check. The `mail:drain` CLI command always passes `null`.
 */
final class DrainMailOutbox
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const MAX_ATTEMPTS       = 3;

    public function __construct(
        private readonly MailOutboxRepositoryInterface $outboxRepo,
        private readonly MailSuppressionRepositoryInterface $suppressionRepo,
        private readonly TenantCommunicationSettingsRepositoryInterface $settingsRepo,
        private readonly MailerInterface $mailer,
        private readonly MarkSuppressedRecipientsInPending $markSuppressed,
    ) {
    }

    public function execute(Input $input, ?ActingUser $acting = null): Output
    {
        $sent    = 0;
        $bounced = 0;
        $failed  = 0;
        $retried = 0;

        // Step 1 — pre-flag any queued recipients that have been suppressed
        // since the row was queued.
        $this->markSuppressed->execute(new MarkInput(), null);

        // Step 2 — pick the next batch (atomic SELECT … FOR UPDATE SKIP LOCKED).
        $batchSize = $input->batchSize ?? self::DEFAULT_BATCH_SIZE;
        $rows = $this->outboxRepo->pickNextForSending($batchSize);

        // Step 3 — try to send each picked row.
        foreach ($rows as $row) {
            $settings = $this->settingsRepo->findForTenant($row->tenantId);

            try {
                $this->mailer->send($row, $settings);
                $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Sent);
                $sent++;
            } catch (MailerHardBounceException $e) {
                $this->suppressionRepo->add(new MailSuppression(
                    tenantId:         $row->tenantId,
                    emailAddress:     $row->recipientEmail,
                    reason:           SuppressionReason::HardBounce,
                    suppressedAt:     new \DateTimeImmutable(),
                    smtpResponseCode: $e->smtpCode,
                    suppressedBy:     null,
                ));
                $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Bounced, $e->getMessage());
                $bounced++;
            } catch (MailerSoftBounceException $e) {
                if ($row->attemptCount + 1 >= self::MAX_ATTEMPTS) {
                    $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Failed, $e->getMessage());
                    $failed++;
                } else {
                    $this->outboxRepo->incrementAttempt($row->id, $e->getMessage());
                    $retried++;
                }
            } catch (MailerTransportException | SmtpNotConfigured $e) {
                $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Failed, $e->getMessage());
                $failed++;
            }
        }

        return new Output($sent, $bounced, $failed, $retried);
    }
}
