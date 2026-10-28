<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Model\Queue;

use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\Lock;
use Bitlane\Search\Model\Support\State;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

/**
 * Runs due jobs, one runner at a time, for a time budget and while memory lasts.
 *
 * Magento's cron starts it every minute in the extension's own cron group
 * (etc/crontab.xml, Cron\Run), so a long catalogue reindex doesn't hold it;
 * bin/magento bitlane:run runs it from Supervisor too, and the Bitlane screen
 * while it's open (docs/magento-plan.md MG10). Storefront requests never run
 * jobs. A job's type names its handler (HandlerInterface, etc/di.xml). A
 * handler that throws BitlaneError is retried with backoff (Retry); one that
 * gives up is kept a week with its error (the Jobs tab), and each run prunes
 * older ones.
 */
class Runner
{
    /**
     * @param Jobs $jobs
     * @param Lock $lock
     * @param State $state
     * @param Retry $retry
     * @param DirectoryList $directories
     * @param LoggerInterface $logger
     * @param array<string,HandlerInterface> $handlers by job type
     */
    public function __construct(
        private readonly Jobs $jobs,
        private readonly Lock $lock,
        private readonly State $state,
        private readonly Retry $retry,
        private readonly DirectoryList $directories,
        private readonly LoggerInterface $logger,
        private readonly array $handlers = []
    ) {
    }

    /**
     * Runs due jobs for up to $seconds; how many ran (0 when another runner is at work).
     *
     * @param int $seconds
     * @return int
     */
    public function run(int $seconds): int
    {
        $ran = 0;
        $this->lock->attempt('runner', function () use ($seconds, &$ran): void {
            $this->state->set('runner_at', (string) time());
            $this->jobs->prune();
            $until = microtime(true) + $seconds;
            $memory = $this->memoryLimit();
            while (microtime(true) < $until && ($memory <= 0 || memory_get_usage(true) < $memory * 0.7)) {
                $job = $this->jobs->claim();
                if ($job === null) {
                    break;
                }
                $this->execute($job);
                ++$ran;
            }
        });

        return $ran;
    }

    /**
     * When a runner last started (Unix seconds); 0 never.
     *
     * @return int
     */
    public function lastRun(): int
    {
        return (int) $this->state->get('runner_at');
    }

    /**
     * When the oldest due job became due (Unix seconds); 0 when none waits.
     *
     * @return int
     */
    public function waitingSince(): int
    {
        return $this->jobs->oldestDue();
    }

    /**
     * Magento's cron line, which runs the extension's cron group with the others.
     *
     * @return string
     */
    public function cronLine(): string
    {
        return '* * * * * php ' . $this->directories->getRoot() . '/bin/magento cron:run >/dev/null 2>&1';
    }

    /**
     * The command a process manager (Supervisor) can keep running instead.
     *
     * @return string
     */
    public function command(): string
    {
        return 'php ' . $this->directories->getRoot() . '/bin/magento bitlane:run';
    }

    /**
     * Runs one job and records its outcome.
     *
     * @param array $job as Jobs::claim() gives it
     * @phpstan-param array{id: int, store: int, type: string, args: array<mixed>, attempts: int} $job
     * @return void
     */
    private function execute(array $job): void
    {
        $handler = $this->handlers[$job['type']] ?? null;
        if ($handler === null) {
            // A type no version of the extension handles any more.
            $this->jobs->done($job['id']);

            return;
        }
        try {
            $handler->run($job['store'], $job['args']);
            $this->jobs->done($job['id']);
        } catch (BitlaneError $error) {
            $this->failed($job, $error);
        } catch (\Throwable $e) {
            $this->logger->error('Bitlane: ' . $job['type'] . ': ' . $e->getMessage(), ['exception' => $e]);
            $this->failed($job, new BitlaneError('unexpected', $e->getMessage()));
        }
    }

    /**
     * Retries a failed job with backoff, or gives it up.
     *
     * @param array $job as Jobs::claim() gives it
     * @phpstan-param array{id: int, store: int, type: string, args: array<mixed>, attempts: int} $job
     * @param BitlaneError $error
     * @return void
     */
    private function failed(array $job, BitlaneError $error): void
    {
        $delay = $this->retry->after($error, $job['attempts']);
        if ($delay === null) {
            $this->jobs->fail($job['id'], $error->getMessage());
        } else {
            $this->jobs->retry($job['id'], $delay, $error->getMessage());
        }
    }

    /**
     * PHP's memory limit in bytes; 0 for none.
     *
     * @return int
     */
    private function memoryLimit(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 0;
        }
        $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];

        return (int) $limit * ($units[strtolower(substr($limit, -1))] ?? 1);
    }
}
