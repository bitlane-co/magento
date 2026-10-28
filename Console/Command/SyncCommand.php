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
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Sync\Sync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bin/magento bitlane:sync [<source>] [--store=<id>]: starts full syncs, as Resync on the screen.
 *
 * The jobs run as usual: from the cron, bitlane:run or the screen.
 */
class SyncCommand extends Command
{
    /**
     * @param ConnectionStore $connection
     * @param Site $site
     * @param Sync $sync
     */
    public function __construct(
        private readonly ConnectionStore $connection,
        private readonly Site $site,
        private readonly Sync $sync
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('bitlane:sync')
            ->setDescription('Syncs a source (product, category, cms_page…) in full again, or every chosen one.')
            ->addArgument('source', InputArgument::OPTIONAL, 'The source; every chosen one without')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'A store (group) id; every connected one without');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getArgument('source') ?: null;
        $only = $input->getOption('store');
        $started = 0;
        foreach ($this->site->stores() as $store) {
            $storeId = (int) $store->getId();
            if (($only !== null && (int) $only !== $storeId) || !$this->connection->integrationId($storeId)) {
                continue;
            }
            $sources = $this->sync->resync($store, $source);
            $output->writeln($this->site->name($store) . ': ' . ($sources
                ? 'full sync started for ' . implode(', ', $sources)
                : 'it doesn’t sync ' . $source));
            ++$started;
        }
        if (!$started) {
            $output->writeln('No connected store to sync.');
        }

        return Command::SUCCESS;
    }
}
