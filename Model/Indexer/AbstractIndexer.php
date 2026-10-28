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

namespace Bitlane\Search\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * One of the sync's indexers (etc/indexer.xml): its changelog's ids become jobs, so Magento's indexing stays quick.
 *
 * A full reindex (bin/magento indexer:reindex, which deploy scripts run)
 * does nothing: a full sync starts from the Bitlane screen or bitlane:sync.
 */
abstract class AbstractIndexer implements IndexerActionInterface, MviewActionInterface
{
    /**
     * Queues the changed ids' jobs.
     *
     * @param list<int> $ids
     * @return void
     */
    abstract protected function changed(array $ids): void;

    /**
     * @inheritdoc
     */
    public function execute($ids)
    {
        $this->changed(array_values(array_map('intval', (array) $ids)));
    }

    // phpcs:disable Magento2.CodeAnalysis.EmptyBlock -- deploys reindex everything; a full sync is asked for.
    /**
     * @inheritdoc
     */
    public function executeFull()
    {
    }
    // phpcs:enable

    /**
     * @inheritdoc
     */
    public function executeList(array $ids)
    {
        $this->execute($ids);
    }

    /**
     * @inheritdoc
     */
    public function executeRow($id)
    {
        $this->execute([$id]);
    }
}
