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

use Bitlane\Search\Api\ChangesInterface;
use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Group;

/**
 * Changed items, queued as jobs (type sync) in every store that syncs their source, which the runner writes.
 *
 * The extension's indexers report the catalogue's changes here
 * (docs/magento-plan.md MG9); a module reports its own source's. A changed
 * product is synced through the products shoppers see: a variant's change
 * is its configurable's (bundle and grouped products' parts too).
 */
class Changes implements ChangesInterface, HandlerInterface
{
    public const JOB = 'sync';

    /**
     * @param Jobs $jobs
     * @param Indexer $indexer
     * @param SyncState $state
     * @param Settings $settings
     * @param Site $site
     * @param ConnectionStore $connection
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly Jobs $jobs,
        private readonly Indexer $indexer,
        private readonly SyncState $state,
        private readonly Settings $settings,
        private readonly Site $site,
        private readonly ConnectionStore $connection,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function add(string $source, array $ids): void
    {
        foreach ($this->stores() as $store) {
            $this->queue($store, $source, $ids);
        }
    }

    /**
     * Queues items to sync in one store, when it syncs their source: 100 products a job, 200 of anything else.
     *
     * @param Group $store
     * @param string $source
     * @param array<int|string> $ids
     * @return void
     */
    public function queue(Group $store, string $source, array $ids): void
    {
        if (!in_array($source, (array) $this->settings->get($store)['sources'], true)) {
            return;
        }
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $ids),
            static fn (string $id) => $id !== '0' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id)
        )));
        if ($ids && $source === 'product') {
            $ids = $this->withParents($ids);
        }
        foreach (array_chunk($ids, $source === 'product' ? 100 : 200) as $chunk) {
            $this->jobs->push((int) $store->getId(), self::JOB, [$source, $chunk]);
        }
    }

    /**
     * A sync job: the items' documents in every store view, stamped with the source's current run.
     *
     * @param int $storeId
     * @param array<mixed> $args the source, its items
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        $store = $this->site->store($storeId);
        $source = (string) ($args[0] ?? '');
        if ($store) {
            $ids = array_values(array_map('strval', (array) ($args[1] ?? [])));
            $this->indexer->sync($store, $source, $ids, $this->state->run($storeId, $source));
        }
    }

    /**
     * The stores that sync: connected, with a setup Bitlane made ready.
     *
     * @return list<Group>
     */
    public function stores(): array
    {
        if (!$this->connection->connected()) {
            return [];
        }

        return array_values(array_filter(
            $this->site->stores(),
            fn (Group $store) => $this->connection->integrationId((int) $store->getId()) !== null
                && !empty($this->state->get((int) $store->getId())['sources'])
        ));
    }

    /**
     * Products and the products that have them as parts (a configurable, a bundle, a grouped product).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function withParents(array $ids): array
    {
        $db = $this->resource->getConnection();
        $parents = $db->fetchCol(
            $db->select()->distinct()->from($this->resource->getTableName('catalog_product_relation'), 'parent_id')
                ->where('child_id IN (?)', array_map('intval', $ids))
        );

        return array_values(array_unique(array_merge($ids, array_map('strval', $parents))));
    }
}
