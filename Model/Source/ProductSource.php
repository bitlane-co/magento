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

namespace Bitlane\Search\Model\Source;

use Bitlane\Search\Api\Context;
use Bitlane\Search\Api\SourceInterface;
use Bitlane\Search\Model\Documents\ProductDocuments;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;

/**
 * The store's products a shopper can search: on its website, enabled, visible in search (docs/magento-plan.md MG6).
 *
 * Its items are the products shoppers see (a configurable, not its
 * children); their documents come from ProductDocuments.
 */
class ProductSource implements SourceInterface
{
    /**
     * @param CollectionFactory $collections
     * @param AttributeCollectionFactory $attributes
     * @param ProductDocuments $documents
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly AttributeCollectionFactory $attributes,
        private readonly ProductDocuments $documents,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'product';
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string) __('Products');
    }

    /**
     * @inheritdoc
     */
    public function kind(): string
    {
        return self::PRODUCTS;
    }

    /**
     * @inheritdoc
     */
    public function count(Group $store): int
    {
        return $this->searchable($store)->getSize();
    }

    /**
     * The price, the categories, availability, and every attribute shoppers filter by.
     *
     * Those used in the layered navigation or in search results, in their
     * position's order.
     *
     * @param int $storeViewId
     * @return array<string,string>
     */
    public function facets(int $storeViewId): array
    {
        $facets = [
            'price' => (string) __('Price'),
            'category' => (string) __('Category'),
            ProductDocuments::STOCK_STATUS => (string) __('Availability'),
        ];
        $attributes = $this->attributes->create()
            ->addVisibleFilter()
            ->addFieldToFilter(['is_filterable', 'is_filterable_in_search'], [['gt' => 0], ['gt' => 0]])
            ->addFieldToFilter('frontend_input', ['in' => ['select', 'multiselect', 'boolean']])
            ->setOrder('position', 'ASC');
        foreach ($attributes as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if (!isset($facets[$code])) {
                $facets[$code] = (string) ($attribute->getStoreLabel($storeViewId)
                    ?: $attribute->getFrontendLabel() ?: $code);
            }
        }

        return $facets;
    }

    /**
     * @inheritdoc
     */
    public function ids(Group $store, string $after, int $limit): array
    {
        $select = $this->searchable($store)->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns('e.entity_id')
            ->where('e.entity_id > ?', (int) $after)
            ->reset(\Magento\Framework\DB\Select::ORDER)
            ->order('e.entity_id')
            ->limit($limit);

        return array_map('strval', $this->resource->getConnection()->fetchCol($select));
    }

    /**
     * @inheritdoc
     */
    public function documents(array $ids, Context $context): array
    {
        return $this->documents->build($ids, $context);
    }

    /**
     * When each product or one of its variants last changed.
     *
     * @param Group $store
     * @param list<string> $ids
     * @return array<int|string,int>
     */
    public function modified(Group $store, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = $this->resource->getConnection();
        $own = $db->fetchPairs(
            $db->select()->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'updated_at'])
                ->where('entity_id IN (?)', array_map('intval', $ids))
        );
        $children = $db->fetchPairs(
            $db->select()->from(['l' => $this->resource->getTableName('catalog_product_super_link')], ['parent_id'])
                ->join(
                    ['e' => $this->resource->getTableName('catalog_product_entity')],
                    'e.entity_id = l.product_id',
                    [new \Zend_Db_Expr('MAX(e.updated_at)')]
                )
                ->where('l.parent_id IN (?)', array_map('intval', $ids))->group('l.parent_id')
        );
        $modified = [];
        foreach ($own as $id => $at) {
            $modified[(string) $id] = (int) strtotime((string) max($at, $children[$id] ?? '') . ' UTC');
        }

        return $modified;
    }

    /**
     * The store's products on its website, enabled and visible in search in its default store view.
     *
     * @param Group $store
     * @return Collection
     */
    private function searchable(Group $store): Collection
    {
        $view = $store->getDefaultStore();

        return $this->collections->create()
            ->setStoreId($view instanceof Store ? (int) $view->getId() : 0)
            ->addWebsiteFilter([(int) $store->getWebsiteId()])
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter(
                'visibility',
                ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
            );
    }
}
