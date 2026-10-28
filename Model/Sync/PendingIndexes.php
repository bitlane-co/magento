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

use Bitlane\Search\Model\Support\BitlaneError;
use Magento\Framework\App\ResourceConnection;

/**
 * Whether Magento's own indexes have caught up with products, before their documents read them.
 *
 * Product documents read the price and stock indexes, which Magento's
 * indexers fill from the same changes ours sees (docs/magento-plan.md MG9):
 * a job that ran first would send the old price. In "Update by Schedule", an
 * index is behind on a product while its changelog has the product past the
 * version the indexer processed (mview_state); "Update on Save" indexes at
 * the save, so there's nothing to wait for.
 */
class PendingIndexes
{
    /**
     * The views whose indexes documents read: prices, catalogue rules' prices, stock.
     */
    private const VIEWS = ['catalog_product_price', 'catalogrule_product', 'cataloginventory_stock'];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Throws a retryable error while one of the products waits in one of the views.
     *
     * The job then runs again after Magento's next indexing (every minute).
     *
     * @param list<int> $productIds
     * @return void
     */
    public function wait(array $productIds): void
    {
        if (!$productIds) {
            return;
        }
        $db = $this->resource->getConnection();
        $states = $db->fetchPairs(
            $db->select()->from($this->resource->getTableName('mview_state'), ['view_id', 'version_id'])
                ->where('view_id IN (?)', self::VIEWS)->where('mode = ?', 'enabled')
        );
        foreach ($states as $view => $version) {
            $changelog = $this->resource->getTableName($view . '_cl');
            if (!$db->isTableExists($changelog)) {
                continue;
            }
            $behind = $db->fetchOne(
                $db->select()->from($changelog, 'version_id')
                    ->where('version_id > ?', (int) $version)->where('entity_id IN (?)', $productIds)->limit(1)
            );
            if ($behind) {
                throw new BitlaneError('index_pending', sprintf('Magento’s %s index hasn’t caught up yet.', $view));
            }
        }
    }
}
