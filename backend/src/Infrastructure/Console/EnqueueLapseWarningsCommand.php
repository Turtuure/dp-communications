<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use DaemsModule\Communications\Application\EnqueueLapseWarnings\EnqueueLapseWarnings;
use DaemsModule\Communications\Application\EnqueueLapseWarnings\Input;

/**
 * `bin/console mail:enqueue-lapse-warnings` — daily 08:00 cron (spec § 5.9).
 *
 * For each tenant, predicts when 0.7's `membership:lapse-inactive-members`
 * cron will lapse a member (2 consecutive OVERDUE years) and enqueues a
 * §4-warning outbox row `lapse_warning_days_before` days ahead. Idempotent
 * within a 24h window.
 */
final class EnqueueLapseWarningsCommand implements CommandInterface
{
    public function __construct(
        private readonly EnqueueLapseWarnings $useCase,
        private readonly LockManager $lockManager,
        private readonly CronLogger $logger,
    ) {}

    public function name(): string
    {
        return 'mail:enqueue-lapse-warnings';
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
                'tenants_considered'       => $out->tenantsConsidered,
                'tenants_skipped_no_smtp'  => $out->tenantsSkippedNoSmtp,
                'duration_ms'              => (int) ((microtime(true) - $tStart) * 1000),
            ]);

            fwrite(STDOUT, sprintf(
                "mail:enqueue-lapse-warnings — enqueued=%d, tenants=%d, no_smtp=%d\n",
                $out->enqueued,
                $out->tenantsConsidered,
                $out->tenantsSkippedNoSmtp,
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->logger->error([
                'message' => $e->getMessage(),
                'class'   => $e::class,
            ]);
            fwrite(STDERR, "mail:enqueue-lapse-warnings — ERROR: {$e->getMessage()}\n");
            return 1;
        } finally {
            $this->lockManager->release($this->name());
        }
    }
}
