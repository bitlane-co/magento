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

use Bitlane\Search\Model\Sync\Changes;

/**
 * Changed products sync in every store that syncs products; a variant's change is its configurable's.
 */
class Products extends AbstractIndexer
{
    /**
     * @param Changes $changes
     */
    public function __construct(private readonly Changes $changes)
    {
    }

    /**
     * @inheritdoc
     */
    protected function changed(array $ids): void
    {
        $this->changes->add('product', $ids);
    }
}
