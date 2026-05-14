<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use DaemsModule\Communications\Application\RetentionCleanup\Input;
use DaemsModule\Communications\Application\RetentionCleanup\RetentionCleanup;

/**
 * `bin/console mail:retention-cleanup` — weekly GDPR retention pass.
 *
 * Pseudonymizes every `mail_outbox` row whose `queued_at` is older than
 * 24 months (configurable via `--cutoff-months=<int>`). Empties body fields,
 * resets payload_vars to {}, hashes recipient_email, and stamps
 * `pseudonymized_at` so subsequent passes skip the row.
 *
 * Schedule (crontab.example): Sundays 03:00. Lock-guarded against
 * overlapping runs.
 */
final class MailRetentionCleanupCommand implements CommandInterface
{
    public function __construct(
        private readonly RetentionCleanup $useCase,
        private readonly LockManager $lockManager,
        private readonly CronLogger $logger,
    ) {
    }

    public function name(): string
    {
        return 'mail:retention-cleanup';
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
            $cutoffMonths = 24;
            if (isset($args['cutoff-months']) && is_string($args['cutoff-months']) && ctype_digit($args['cutoff-months'])) {
                $cutoffMonths = (int) $args['cutoff-months'];
            }
            $cutoff = new \DateTimeImmutable('-' . $cutoffMonths . ' months');

            $tStart = microtime(true);
            $output = $this->useCase->execute(new Input(cutoff: $cutoff));

            $this->logger->info([
                'summary'             => true,
                'pseudonymized_count' => $output->pseudonymizedCount,
                'cutoff'              => $cutoff->format(DATE_ATOM),
                'cutoff_months'       => $cutoffMonths,
                'duration_ms'         => (int) ((microtime(true) - $tStart) * 1000),
                'command'             => $this->name(),
            ]);

            fwrite(
                STDOUT,
                sprintf(
                    "mail:retention-cleanup — pseudonymized=%d (cutoff=%s)\n",
                    $output->pseudonymizedCount,
                    $cutoff->format('Y-m-d'),
                ),
            );

            return 0;
        } finally {
            $this->lockManager->release($this->name());
        }
    }
}
