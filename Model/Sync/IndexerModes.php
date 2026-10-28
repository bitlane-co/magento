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

namespace Bitlane\Search\Model\Sync;

use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

/**
 * The sync's indexers in "Update by Schedule", the only mode in which they see changes (docs/magento-plan.md MG9).
 *
 * An admin or a deploy script may switch them to "Update on Save": the
 * screen says so, and the install and the daily check switch them back.
 */
class IndexerModes
{
    public const INDEXERS = ['bitlane_products', 'bitlane_categories', 'bitlane_options', 'bitlane_cms_pages'];

    /**
     * @param IndexerRegistry $indexers
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly IndexerRegistry $indexers,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The sync's indexers that aren't on schedule.
     *
     * @return list<string>
     */
    public function onSave(): array
    {
        return array_values(array_filter(
            self::INDEXERS,
            fn (string $id) => !$this->indexers->get($id)->isScheduled()
        ));
    }

    /**
     * Puts the sync's indexers on schedule, logging those that weren't.
     *
     * @return void
     */
    public function schedule(): void
    {
        foreach ($this->onSave() as $id) {
            $this->indexers->get($id)->setScheduled(true);
            $this->logger->warning(
                'Bitlane: the indexer ' . $id . ' was set to Update on Save, which misses changes;'
                . ' it’s back on Update by Schedule.'
            );
        }
    }
}
