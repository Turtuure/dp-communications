<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use DaemsModule\Communications\Application\EnqueuePaymentReminders\EnqueuePaymentReminders;
use DaemsModule\Communications\Application\EnqueuePaymentReminders\Input;

/**
 * `bin/console mail:enqueue-payment-reminders` — daily 07:00 cron (spec § 5.9).
 *
 * Iterates every tenant, finds invoices matching the pre-due + post-due
 * windows, and enqueues `payment_reminder` outbox rows. Idempotent within
 * a 24h window — safe to run twice the same day.
 *
 * No flags. A future iteration may add `--tenant=<slug>` for ad-hoc reruns.
 */
final class EnqueuePaymentRemindersCommand implements CommandInterface
{
    public function __construct(
        private readonly EnqueuePaymentReminders $useCase,
        private readonly LockManager $lockManager,
        private readonly CronLogger $logger,
    ) {}

    public function name(): string
    {
        return 'mail:enqueue-payment-reminders';
    }

    /**
     * @param array<string,string|bool> $args
     */
    public function execute(array $args): int
    {
        if (!$this->lockManager->acquire($this->name())) {
            $this->logger->info(['message' => 'lock held, skipping', 'command' => $this->name()]);
            return 0;
        }

        try {
            $tStart = microtime(true);
            $out    = $this->useCase->execute(new Input(), null);

            $this->logger->info([
                'summary'                  => true,
                'enqueued'                 => $out->enqueued,
                'pre_due'                  => $out->preDue,
                'post_due'                 => $out->postDue,
                'tenants_considered'       => $out->tenantsConsidered,
                'tenants_skipped_no_smtp'  => $out->tenantsSkippedNoSmtp,
                'duration_ms'              => (int) ((microtime(true) - $tStart) * 1000),
            ]);

            fwrite(STDOUT, sprintf(
                "mail:enqueue-payment-reminders — enqueued=%d (pre_due=%d, post_due=%d), tenants=%d, no_smtp=%d\n",
                $out->enqueued,
                $out->preDue,
                $out->postDue,
                $out->tenantsConsidered,
                $out->tenantsSkippedNoSmtp,
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->logger->error([
                'message' => $e->getMessage(),
                'class'   => $e::class,
            ]);
            fwrite(STDERR, "mail:enqueue-payment-reminders — ERROR: {$e->getMessage()}\n");
            return 1;
        } finally {
            $this->lockManager->release($this->name());
        }
    }
}
