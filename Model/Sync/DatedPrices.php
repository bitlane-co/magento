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

use Bitlane\Search\Model\Support\State;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;

/**
 * Prices that change with the date, which nothing saves (docs/magento-plan.md MG11).
 *
 * A special price starts on its from date and ends after its to date, a
 * catalogue rule likewise; Magento reindexes their prices in its hourly price
 * refresh and its nightly rules run. Once a day per store, after midnight in
 * its timezone, the products whose special price or rule starts that day or
 * ended the day before sync (PendingIndexes waits for Magento's own index).
 * The cron job bitlane_dated_prices runs it every hour, so each timezone's
 * midnight is caught within the hour.
 */
class DatedPrices
{
    /**
     * @param Changes $changes
     * @param State $state
     * @param ResourceConnection $resource
     * @param EavConfig $eavConfig
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly Changes $changes,
        private readonly State $state,
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Each syncing store whose day turned since it last ran gets that day's products queued.
     *
     * @return void
     */
    public function run(): void
    {
        foreach ($this->changes->stores() as $store) {
            $storeId = (int) $store->getId();
            $today = $this->today($store);
            if ($this->state->get('dated_prices', $storeId) === $today) {
                continue;
            }
            $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            $products = $this->specialPrices($today, $yesterday);
            array_push($products, ...$this->catalogRules($store, $today, $yesterday));
            $this->changes->queue($store, 'product', $products);
            $this->state->set('dated_prices', $today, $storeId);
        }
    }

    /**
     * Today in the store's timezone (Y-m-d).
     *
     * @param Group $store
     * @return string
     */
    private function today(Group $store): string
    {
        $timezone = (string) $this->config->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            (int) $store->getDefaultStoreId()
        );

        return (new \DateTimeImmutable('now', new \DateTimeZone($timezone ?: 'UTC')))->format('Y-m-d');
    }

    /**
     * Products whose special price starts today or ended yesterday.
     *
     * @param string $today
     * @param string $yesterday
     * @return list<int>
     */
    private function specialPrices(string $today, string $yesterday): array
    {
        $from = (int) $this->eavConfig->getAttribute('catalog_product', 'special_from_date')->getId();
        $to = (int) $this->eavConfig->getAttribute('catalog_product', 'special_to_date')->getId();
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_entity_datetime');
        // Adobe Commerce keys these rows by row_id, Open Source by entity_id.
        $column = $db->tableColumnExists($table, 'row_id') ? 'row_id' : 'entity_id';
        $select = $db->select()->distinct()->from(['d' => $table], [])
            ->where(
                '(d.attribute_id = ' . $from . ' AND DATE(d.value) = ' . $db->quote($today) . ')'
                . ' OR (d.attribute_id = ' . $to . ' AND DATE(d.value) = ' . $db->quote($yesterday) . ')'
            );
        if ($column === 'row_id') {
            $select->join(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                'e.row_id = d.row_id',
                ['entity_id']
            );
        } else {
            $select->columns(['entity_id' => 'd.entity_id']);
        }

        return array_map('intval', $db->fetchCol($select));
    }

    /**
     * Products of the catalogue rules on the store's website that start today or ended yesterday.
     *
     * @param Group $store
     * @param string $today
     * @param string $yesterday
     * @return list<int>
     */
    private function catalogRules(Group $store, string $today, string $yesterday): array
    {
        $db = $this->resource->getConnection();
        $rules = $this->resource->getTableName('catalogrule');
        if (!$db->isTableExists($rules)) {
            return [];
        }

        return array_map('intval', $db->fetchCol(
            $db->select()->distinct()->from(['r' => $rules], [])
                ->join(
                    ['p' => $this->resource->getTableName('catalogrule_product')],
                    'p.rule_id = r.rule_id',
                    ['product_id']
                )
                ->where('r.is_active = ?', 1)
                ->where('p.website_id = ?', (int) $store->getWebsiteId())
                ->where('r.from_date = ' . $db->quote($today) . ' OR r.to_date = ' . $db->quote($yesterday))
        ));
    }
}
