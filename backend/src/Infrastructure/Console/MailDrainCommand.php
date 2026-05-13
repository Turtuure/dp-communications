<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Console;

use Daems\Infrastructure\Console\CommandInterface;
use Daems\Infrastructure\Console\CronLogger;
use Daems\Infrastructure\Console\LockManager;
use DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox;
use DaemsModule\Communications\Application\DrainMailOutbox\Input;

/**
 * `bin/console mail:drain` — runs every minute via cron (per spec § 7.4).
 *
 * Picks the next 50 queued mail rows and tries to send each one. Uses a
 * file lock so concurrent ticks (slow drains, overlapping schedules) cannot
 * stack up and double-send. Result counters are written to the cron log.
 *
 * Supported flag:
 *   --batch-size=<int>    Override the default 50-row batch.
 */
final class MailDrainCommand implements CommandInterface
{
    public function __construct(
        private readonly DrainMailOutbox $useCase,
        private readonly LockManager $lockManager,
        private readonly CronLogger $logger,
    ) {
    }

    public function name(): string
    {
        return 'mail:drain';
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
            $batchSize = null;
            if (isset($args['batch-size']) && is_string($args['batch-size']) && ctype_digit($args['batch-size'])) {
                $batchSize = (int) $args['batch-size'];
            }

            $tStart = microtime(true);
            $output = $this->useCase->execute(new Input(batchSize: $batchSize), null);

            $this->logger->info([
                'summary'     => true,
                'sent'        => $output->sent,
                'bounced'     => $output->bounced,
                'failed'      => $output->failed,
                'retried'     => $output->retried,
                'duration_ms' => (int) ((microtime(true) - $tStart) * 1000),
            ]);

            fwrite(STDOUT, sprintf(
                "mail:drain — sent=%d, bounced=%d, failed=%d, retried=%d\n",
                $output->sent,
                $output->bounced,
                $output->failed,
                $output->retried,
            ));

            return 0;
        } catch (\Throwable $e) {
            $this->logger->error([
                'message' => $e->getMessage(),
                'class'   => $e::class,
            ]);
            fwrite(STDERR, "mail:drain — ERROR: {$e->getMessage()}\n");
            return 1;
        } finally {
            $this->lockManager->release($this->name());
        }
    }
}
