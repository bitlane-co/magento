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
use Bitlane\Search\Model\Documents\Fields;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Group;

/**
 * The store's active categories under its root, a document each (type "Category", subtitle its path).
 */
class CategorySource implements SourceInterface
{
    /**
     * @param CollectionFactory $collections
     * @param ResourceConnection $resource
     * @param Fields $fields
     * @param OtherDocuments $documents
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly ResourceConnection $resource,
        private readonly Fields $fields,
        private readonly OtherDocuments $documents
    ) {
    }

    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'category';
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string) __('Categories');
    }

    /**
     * @inheritdoc
     */
    public function kind(): string
    {
        return self::OTHER;
    }

    /**
     * @inheritdoc
     */
    public function count(Group $store): int
    {
        return count($this->walk($store, 0, PHP_INT_MAX));
    }

    /**
     * @inheritdoc
     */
    public function facets(int $storeViewId): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function ids(Group $store, string $after, int $limit): array
    {
        return array_map('strval', $this->walk($store, (int) $after, $limit));
    }

    /**
     * @inheritdoc
     */
    public function documents(array $ids, Context $context): array
    {
        $documents = array_fill_keys($ids, []);
        $root = (int) $context->store->getRootCategoryId();
        $view = (int) $context->storeView->getId();
        $collection = $this->collections->create()
            ->setStoreId($view)
            ->addAttributeToSelect(['name', 'description', 'image', 'is_active', 'url_key'])
            ->addAttributeToFilter('path', ['like' => '1/' . $root . '/%'])
            ->addUrlRewriteToResult();
        // Every category under the root: a document's path needs its parents' names.
        $names = [];
        $active = [];
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
            $active[(int) $category->getId()] = (bool) $category->getIsActive();
        }
        foreach ($collection as $category) {
            $id = (string) $category->getId();
            if (!array_key_exists($id, $documents)) {
                continue;
            }
            $path = array_slice(array_map('intval', explode('/', (string) $category->getPath())), 2);
            if (array_filter($path, static fn (int $part) => !($active[$part] ?? false))) {
                continue;
            }
            $image = (string) $category->getImageUrl();
            $document = $this->documents->finish($this->id(), $id, [
                'title' => (string) $category->getName(),
                'permalink' => (string) $category->getUrl(),
                'type' => (string) __('Category'),
                'subtitle' => implode(' › ', array_map(
                    static fn (int $part) => $names[$part] ?? '',
                    array_slice($path, 0, -1)
                )),
                'image' => $image !== '' ? ['src' => $image, 'alt' => (string) $category->getName()] : null,
                'content' => $this->fields->text((string) $category->getData('description')),
                'created_at' => (int) strtotime((string) $category->getData('created_at') . ' UTC'),
                'modified_at' => (int) strtotime((string) $category->getData('updated_at') . ' UTC'),
                'menu_order' => (int) $category->getPosition(),
            ], $view);
            $documents[$id] = $document ? [$document] : [];
        }

        return $documents;
    }

    /**
     * @inheritdoc
     */
    public function modified(Group $store, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $db = $this->resource->getConnection();
        $rows = $db->fetchPairs(
            $db->select()->from($this->resource->getTableName('catalog_category_entity'), ['entity_id', 'updated_at'])
                ->where('entity_id IN (?)', array_map('intval', $ids))
        );

        return array_map(static fn ($at) => (int) strtotime((string) $at . ' UTC'), $rows);
    }

    /**
     * The store's category ids under its root, after $after, in order.
     *
     * @param Group $store
     * @param int $after
     * @param int $limit
     * @return list<int>
     */
    private function walk(Group $store, int $after, int $limit): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->resource->getTableName('catalog_category_entity'), 'entity_id')
            ->where('path LIKE ?', '1/' . (int) $store->getRootCategoryId() . '/%')
            ->where('entity_id > ?', $after)
            ->order('entity_id');
        if ($limit < PHP_INT_MAX) {
            $select->limit($limit);
        }

        return array_map('intval', $db->fetchCol($select));
    }
}
