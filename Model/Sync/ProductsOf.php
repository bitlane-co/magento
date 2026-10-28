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

use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Site;
use Magento\Framework\App\ResourceConnection;

/**
 * The products of something renamed that their documents carry: a category, an option ("Red" now "Crimson").
 *
 * The job products_of finds them 500 at a time in the background and queues
 * them to sync, so a rename in the admin costs the admin nothing.
 */
class ProductsOf implements HandlerInterface
{
    public const JOB = 'products_of';

    /**
     * @param Jobs $jobs
     * @param Changes $changes
     * @param Site $site
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly Jobs $jobs,
        private readonly Changes $changes,
        private readonly Site $site,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Queues the search for the products of categories or options, in every store that syncs products.
     *
     * @param string $kind category or option
     * @param list<int> $ids
     * @return void
     */
    public function queue(string $kind, array $ids): void
    {
        foreach ($this->changes->stores() as $store) {
            foreach (array_unique($ids) as $id) {
                $this->jobs->push((int) $store->getId(), self::JOB, [$kind, $id, 0], 0, $kind . ':' . $id);
            }
        }
    }

    /**
     * 500 of the products, queued to sync, then the next 500.
     *
     * @param int $storeId
     * @param array<mixed> $args the kind, its id, the last product id done
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        [$kind, $id, $after] = [(string) ($args[0] ?? ''), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
        $db = $this->resource->getConnection();
        $select = match ($kind) {
            // Its subtree: a category's name is in its children's products' values too.
            'category' => $db->select()->distinct()
                ->from(['cp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
                ->join(
                    ['c' => $this->resource->getTableName('catalog_category_entity')],
                    'c.entity_id = cp.category_id',
                    []
                )
                ->where('c.entity_id = ? OR c.path LIKE ' . $db->quote('%/' . $id . '/%'), $id)
                ->where('cp.product_id > ?', $after)->order('cp.product_id'),
            // Filterable attributes' values, select and multiselect alike, as the layered navigation reads them.
            'option' => $db->select()->distinct()
                ->from($this->resource->getTableName('catalog_product_index_eav'), ['entity_id'])
                ->where('value = ?', $id)->where('entity_id > ?', $after)->order('entity_id'),
            default => null,
        };
        $store = $this->site->store($storeId);
        if ($select === null || !$store) {
            return;
        }
        $ids = array_map('intval', $db->fetchCol($select->limit(500)));
        $this->changes->queue($store, 'product', $ids);
        if (count($ids) === 500) {
            $this->jobs->push($storeId, self::JOB, [$kind, $id, (int) end($ids)]);
        }
    }
}
