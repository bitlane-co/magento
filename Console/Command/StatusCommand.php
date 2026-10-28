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

namespace Bitlane\Search\Console\Command;

use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Queue\Runner;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Sync\IndexerModes;
use Bitlane\Search\Model\Sync\Sync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bin/magento bitlane:status: each store's sync, its jobs, the runner and the indexers' mode, as the screen shows them.
 */
class StatusCommand extends Command
{
    /**
     * @param ConnectionStore $connection
     * @param Site $site
     * @param Sync $sync
     * @param Jobs $jobs
     * @param Runner $runner
     * @param IndexerModes $indexerModes
     */
    public function __construct(
        private readonly ConnectionStore $connection,
        private readonly Site $site,
        private readonly Sync $sync,
        private readonly Jobs $jobs,
        private readonly Runner $runner,
        private readonly IndexerModes $indexerModes
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('bitlane:status')
            ->setDescription('Shows each store’s Bitlane sync, its jobs and the runner.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->connection->connected()) {
            $output->writeln('Not connected to Bitlane: connect on the Bitlane screen in the admin.');

            return Command::SUCCESS;
        }
        foreach ($this->site->stores() as $store) {
            $storeId = (int) $store->getId();
            $name = $this->site->name($store);
            $output->writeln(sprintf('<info>%s</info> (%d, %s)', $name, $storeId, $this->site->url($store)));
            if (!$this->connection->integrationId($storeId)) {
                $output->writeln('  not connected');
                continue;
            }
            $summary = $this->sync->summary($store);
            foreach ($summary['sources'] as $source) {
                $state = 'not synced yet';
                if ($source['running']) {
                    $state = sprintf('syncing, %d of %d', $source['done'], $source['total']);
                } elseif ($source['finished_at']) {
                    $state = 'synced ' . gmdate('Y-m-d H:i', $source['finished_at']) . ' UTC';
                }
                $output->writeln('  ' . $source['id'] . ': ' . $state);
            }
            $output->writeln(sprintf(
                '  jobs: %d waiting, %d due, %d failed; changes waiting: %d',
                $this->jobs->waiting($storeId),
                $this->jobs->waiting($storeId, true),
                $this->jobs->failed($storeId),
                $summary['queued']
            ));
            if ($summary['error']) {
                $output->writeln('  last error: ' . $summary['error']['message']);
            }
        }
        $lastRun = $this->runner->lastRun();
        $output->writeln('Runner: ' . ($lastRun ? 'last ran ' . gmdate('Y-m-d H:i', $lastRun) . ' UTC' : 'never ran'));
        $onSave = $this->indexerModes->onSave();
        if ($onSave) {
            $output->writeln(
                '<comment>On Update on Save, which misses changes: ' . implode(', ', $onSave) . '</comment>'
            );
        }

        return Command::SUCCESS;
    }
}
