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

namespace Bitlane\Search\Model\Documents;

use Bitlane\Search\Api\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * A store view's catalogue as documents need it, read a batch at a time.
 *
 * The store's categories (once), attribute options with their labels and
 * swatches, products' categories, stock, ratings and sales: a query each
 * per batch, never one per product (docs/magento-plan.md Part 3).
 */
class Catalog
{
    /**
     * The store's categories under its root, by id: name, path of ids, URL, position.
     *
     * @var array<int,array{name: string, path: list<int>, url: string, position: int}>|null
     */
    private ?array $categories = null;

    /**
     * Option labels and swatches read so far, by option id.
     *
     * @var array<int,array{label: string, swatch: string}>
     */
    private array $options = [];

    /**
     * @param Context $context
     * @param ResourceConnection $resource
     * @param CategoryCollectionFactory $categoryCollections
     * @param ScopeConfigInterface $config
     * @param Fields $fields
     */
    public function __construct(
        private readonly Context $context,
        private readonly ResourceConnection $resource,
        private readonly CategoryCollectionFactory $categoryCollections,
        private readonly ScopeConfigInterface $config,
        private readonly Fields $fields
    ) {
    }

    /**
     * The category filter's values of products: their categories with the categories above them, the root left out.
     *
     * @param list<int> $productIds
     * @return array<int,list<string>> by product
     */
    public function categoryValues(array $productIds): array
    {
        $categories = $this->categories();
        $db = $this->db();
        $rows = $productIds ? $db->fetchAll(
            $db->select()->from($this->table('catalog_category_product'), ['product_id', 'category_id'])
                ->where('product_id IN (?)', $productIds)
        ) : [];
        $values = [];
        foreach ($rows as $row) {
            $category = $categories[(int) $row['category_id']] ?? null;
            if ($category === null) {
                continue;
            }
            foreach ($category['path'] as $id) {
                $values[(int) $row['product_id']][$id] = $this->fields->value(
                    $categories[$id]['name'],
                    '',
                    $id
                );
            }
        }

        return array_map('array_values', $values);
    }

    /**
     * Each product's place in its first category under the root (catalog_category_product's position).
     *
     * @param list<int> $productIds
     * @return array<int,int>
     */
    public function positions(array $productIds): array
    {
        $categories = $this->categories();
        $db = $this->db();
        $rows = $productIds ? $db->fetchAll(
            $db->select()->from($this->table('catalog_category_product'), ['product_id', 'category_id', 'position'])
                ->where('product_id IN (?)', $productIds)
                ->order(['category_id'])
        ) : [];
        $positions = [];
        foreach ($rows as $row) {
            if (isset($categories[(int) $row['category_id']]) && !isset($positions[(int) $row['product_id']])) {
                $positions[(int) $row['product_id']] = (int) $row['position'];
            }
        }

        return $positions;
    }

    /**
     * The store's active categories under its root, by id.
     *
     * @return array<int,array{name: string, path: list<int>, url: string, position: int}>
     */
    public function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }
        $root = (int) $this->context->store->getRootCategoryId();
        $collection = $this->categoryCollections->create()
            ->setStoreId((int) $this->context->storeView->getId())
            ->addAttributeToSelect(['name', 'is_active', 'url_key', 'include_in_menu'])
            ->addAttributeToFilter('path', ['like' => '1/' . $root . '/%'])
            ->addAttributeToFilter('is_active', ['eq' => 1])
            ->addUrlRewriteToResult();
        $categories = [];
        foreach ($collection as $category) {
            $path = array_map('intval', explode('/', (string) $category->getPath()));
            $categories[(int) $category->getId()] = [
                'name' => (string) $category->getName(),
                // Below the root: 1/<root>/<a>/<b> is a, b.
                'path' => array_slice($path, 2),
                'url' => (string) $category->getUrl(),
                'position' => (int) $category->getPosition(),
            ];
        }
        // A category under an inactive one isn't shown: its path must be all active.
        $this->categories = array_filter(
            $categories,
            static fn (array $category) => !array_diff($category['path'], array_keys($categories))
        );

        return $this->categories;
    }

    /**
     * Options' labels in the store view (else the admin's) and swatches, by option id.
     *
     * A swatch is a hex color, or its image's URL (media/attribute/swatch).
     *
     * @param list<int> $optionIds
     * @return array<int,array{label: string, swatch: string}>
     */
    public function options(array $optionIds): array
    {
        $missing = array_values(array_diff(array_unique($optionIds), array_keys($this->options)));
        if ($missing) {
            $view = (int) $this->context->storeView->getId();
            $db = $this->db();
            $labels = [];
            foreach ($db->fetchAll(
                $db->select()->from($this->table('eav_attribute_option_value'), ['option_id', 'store_id', 'value'])
                    ->where('option_id IN (?)', $missing)->where('store_id IN (?)', [0, $view])
                    ->order('store_id')
            ) as $row) {
                $labels[(int) $row['option_id']] = (string) $row['value'];
            }
            $swatches = [];
            foreach ($db->fetchAll(
                $db->select()
                    ->from($this->table('eav_attribute_option_swatch'), ['option_id', 'store_id', 'type', 'value'])
                    ->where('option_id IN (?)', $missing)->where('store_id IN (?)', [0, $view])
                    ->order('store_id')
            ) as $row) {
                $swatches[(int) $row['option_id']] = $this->swatch((int) $row['type'], (string) $row['value']);
            }
            foreach ($missing as $id) {
                $this->options[$id] = ['label' => $labels[$id] ?? '', 'swatch' => $swatches[$id] ?? ''];
            }
        }

        return array_intersect_key($this->options, array_flip($optionIds));
    }

    /**
     * Whether products can be sold, by product id: salable now, or on backorder when they can't.
     *
     * MSI's stock of the store's website when it has one, else the legacy stock status.
     *
     * @param array<int,string> $skus by product id
     * @return array<int,string> instock, onbackorder or outofstock
     */
    public function stock(array $skus): array
    {
        if (!$skus) {
            return [];
        }
        $db = $this->db();
        $salable = [];
        $stockTable = $this->stockTable();
        if ($stockTable !== null) {
            $bySku = $db->fetchPairs(
                $db->select()->from($stockTable, ['sku', 'is_salable'])->where('sku IN (?)', array_values($skus))
            );
            foreach ($skus as $id => $sku) {
                $salable[$id] = (bool) ($bySku[$sku] ?? false);
            }
        } else {
            $byId = $db->fetchPairs(
                $db->select()->from($this->table('cataloginventory_stock_status'), ['product_id', 'stock_status'])
                    ->where('product_id IN (?)', array_keys($skus))
            );
            foreach (array_keys($skus) as $id) {
                $salable[$id] = (bool) ($byId[$id] ?? false);
            }
        }

        // Salable with nothing on hand: sold on backorder.
        $backorders = (int) $this->config->getValue('cataloginventory/item_options/backorders');
        $items = $db->fetchAll(
            $db->select()->from(
                $this->resource->getTableName('cataloginventory_stock_item'),
                ['product_id', 'qty', 'backorders', 'use_config_backorders']
            )->where('product_id IN (?)', array_keys($skus))
        );
        $backordered = [];
        foreach ($items as $item) {
            $allowed = ($item['use_config_backorders'] ? $backorders : (int) $item['backorders']) > 0;
            $backordered[(int) $item['product_id']] = $allowed && (float) $item['qty'] <= 0;
        }

        $status = [];
        foreach ($salable as $id => $isSalable) {
            $status[$id] = $isSalable ? (($backordered[$id] ?? false) ? 'onbackorder' : 'instock') : 'outofstock';
        }

        return $status;
    }

    /**
     * Ratings of the store view's reviews, by product: average out of 5 and how many.
     *
     * @param list<int> $productIds
     * @return array<int,array{rating: float, count: int}>
     */
    public function ratings(array $productIds): array
    {
        $db = $this->db();
        $rows = $productIds ? $db->fetchAll(
            $db->select()
                ->from($this->table('review_entity_summary'), ['entity_pk_value', 'rating_summary', 'reviews_count'])
                ->where('entity_type = ?', 1)->where('store_id = ?', (int) $this->context->storeView->getId())
                ->where('entity_pk_value IN (?)', $productIds)->where('reviews_count > 0')
        ) : [];
        $ratings = [];
        foreach ($rows as $row) {
            $ratings[(int) $row['entity_pk_value']] = [
                'rating' => round((float) $row['rating_summary'] / 20, 1),
                'count' => (int) $row['reviews_count'],
            ];
        }

        return $ratings;
    }

    /**
     * How many of each product the store view sold (Magento's bestsellers report).
     *
     * @param list<int> $productIds
     * @return array<int,int>
     */
    public function sales(array $productIds): array
    {
        $db = $this->db();

        return $productIds ? array_map('intval', $db->fetchPairs(
            $db->select()->from($this->resource->getTableName('sales_bestsellers_aggregated_yearly'), [
                'product_id',
                new \Zend_Db_Expr('SUM(qty_ordered)'),
            ])->where('store_id = ?', (int) $this->context->storeView->getId())
                ->where('product_id IN (?)', $productIds)->group('product_id')
        )) : [];
    }

    /**
     * Whether shoppers see products that are out of stock (cataloginventory/options/show_out_of_stock).
     *
     * @return bool
     */
    public function showsOutOfStock(): bool
    {
        return $this->config->isSetFlag(
            'cataloginventory/options/show_out_of_stock',
            ScopeInterface::SCOPE_STORE,
            (int) $this->context->storeView->getId()
        );
    }

    /**
     * MSI's stock index of the store's website (inventory_stock_<id>); null without MSI.
     *
     * @return string|null
     */
    private function stockTable(): ?string
    {
        $db = $this->db();
        $channels = $this->resource->getTableName('inventory_stock_sales_channel');
        if (!$db->isTableExists($channels)) {
            return null;
        }
        $website = $this->context->storeView->getWebsite();
        $stockId = (int) $db->fetchOne(
            $db->select()->from($channels, 'stock_id')->where('type = ?', 'website')
                ->where('code = ?', $website ? (string) $website->getCode() : '')
        );
        $table = $this->resource->getTableName('inventory_stock_' . $stockId);

        return $stockId > 0 && $db->isTableExists($table) ? $table : null;
    }

    /**
     * An option's swatch as a filter value carries it: a hex color, an image's URL, or none.
     *
     * Type 1 is a color, 2 an image under media/attribute/swatch; 0 is a text swatch, no picture.
     *
     * @param int $type
     * @param string $value
     * @return string
     */
    private function swatch(int $type, string $value): string
    {
        if ($type === 1 && preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
            return $value;
        }
        if ($type === 2 && $value !== '') {
            $media = (string) $this->context->storeView->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true);

            return $media . 'attribute/swatch' . $value;
        }

        return '';
    }

    /**
     * A table's name, with the install's prefix.
     *
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * The database connection.
     *
     * @return AdapterInterface
     */
    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
