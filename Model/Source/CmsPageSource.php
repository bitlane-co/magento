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
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;

/**
 * The store's active CMS pages, a document each (type "Page"); the 404 and cookies pages left out.
 */
class CmsPageSource implements SourceInterface
{
    /**
     * @param CollectionFactory $collections
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $config
     * @param Fields $fields
     * @param OtherDocuments $documents
     */
    public function __construct(
        private readonly CollectionFactory $collections,
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $config,
        private readonly Fields $fields,
        private readonly OtherDocuments $documents
    ) {
    }

    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'cms_page';
    }

    /**
     * @inheritdoc
     */
    public function label(): string
    {
        return (string) __('CMS pages');
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
        $view = (int) $context->storeView->getId();
        $hidden = $this->hidden($view);
        $home = (string) $this->config->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORE, $view);
        $base = (string) $context->storeView->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
        $pages = $this->collections->create()
            ->addStoreFilter($view)
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('page_id', ['in' => array_map('intval', $ids)]);
        foreach ($pages as $page) {
            $identifier = (string) $page->getIdentifier();
            if (in_array($identifier, $hidden, true)) {
                continue;
            }
            $id = (string) $page->getId();
            $document = $this->documents->finish($this->id(), $id, [
                'title' => (string) $page->getTitle(),
                'permalink' => $identifier === explode('|', $home)[0] ? $base : $base . $identifier,
                'type' => (string) __('Page'),
                'short_description' => $this->fields->text((string) $page->getMetaDescription(), 300),
                'content' => $this->fields->text((string) $page->getContent()),
                'created_at' => (int) strtotime((string) $page->getCreationTime() . ' UTC'),
                'modified_at' => (int) strtotime((string) $page->getUpdateTime() . ' UTC'),
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
            $db->select()->from($this->resource->getTableName('cms_page'), ['page_id', 'update_time'])
                ->where('page_id IN (?)', array_map('intval', $ids))
        );

        return array_map(static fn ($at) => (int) strtotime((string) $at . ' UTC'), $rows);
    }

    /**
     * The active page ids shown in one of the store's views, after $after, in order.
     *
     * @param Group $store
     * @param int $after
     * @param int $limit
     * @return list<int>
     */
    private function walk(Group $store, int $after, int $limit): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->distinct()
            ->from(['p' => $this->resource->getTableName('cms_page')], 'page_id')
            ->join(['s' => $this->resource->getTableName('cms_page_store')], 's.page_id = p.page_id', [])
            ->where('p.is_active = ?', 1)
            ->where('s.store_id IN (?)', array_merge([0], array_map('intval', $store->getStoreIds())))
            ->where('p.identifier NOT IN (?)', $this->hidden((int) $store->getDefaultStoreId()))
            ->where('p.page_id > ?', $after)
            ->order('p.page_id');
        if ($limit < PHP_INT_MAX) {
            $select->limit($limit);
        }

        return array_map('intval', $db->fetchCol($select));
    }

    /**
     * The pages that aren't content: the 404 page and the one shown without cookies.
     *
     * @param int $storeViewId
     * @return list<string>
     */
    private function hidden(int $storeViewId): array
    {
        return array_values(array_filter(array_map(
            fn (string $path) => explode(
                '|',
                (string) $this->config->getValue($path, ScopeInterface::SCOPE_STORE, $storeViewId)
            )[0],
            ['web/default/cms_no_route', 'web/default/cms_no_cookies']
        )));
    }
}
