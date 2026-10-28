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
use Bitlane\Search\Model\Sync\ProductsOf;

/**
 * Changed categories sync as documents, and their products (whose category values carry their names) after them.
 */
class Categories extends AbstractIndexer
{
    /**
     * @param Changes $changes
     * @param ProductsOf $productsOf
     */
    public function __construct(
        private readonly Changes $changes,
        private readonly ProductsOf $productsOf
    ) {
    }

    /**
     * @inheritdoc
     */
    protected function changed(array $ids): void
    {
        $this->changes->add('category', $ids);
        $this->productsOf->queue('category', $ids);
    }
}
