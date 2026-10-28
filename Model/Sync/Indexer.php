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

use Bitlane\Search\Api\Context;
use Bitlane\Search\Api\SourceInterface;
use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Source\SourcePool;
use Bitlane\Search\Model\Support\BitlaneError;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes a source's items to its index in each of the store's store views, as the plugins do.
 *
 * Their documents are upserted (stamped with the run, with only the picked
 * filters), then what the items no longer have there is deleted. A product's
 * documents share its parent_id, so the ones it had are found with it (a
 * variant removed, the product hidden); any other item has one document, its
 * id. Documents are built in an emulation of the store view: its locale,
 * URLs, theme and currency.
 */
class Indexer
{
    /**
     * @param SourcePool $sources
     * @param Settings $settings
     * @param Site $site
     * @param Credentials $credentials
     * @param Meilisearch $meilisearch
     * @param PendingIndexes $pending
     * @param Emulation $emulation
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly SourcePool $sources,
        private readonly Settings $settings,
        private readonly Site $site,
        private readonly Credentials $credentials,
        private readonly Meilisearch $meilisearch,
        private readonly PendingIndexes $pending,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Syncs a source's items in every store view whose index exists.
     *
     * A store view just added has none until Bitlane sets it up; its full
     * sync follows. A full sync doesn't look for documents an item no longer
     * has: its sweep deletes them.
     *
     * @param Group $store
     * @param string $sourceId
     * @param list<string> $ids
     * @param string $run
     * @param bool $full
     * @return void
     */
    public function sync(Group $store, string $sourceId, array $ids, string $run, bool $full = false): void
    {
        $settings = $this->settings->get($store);
        $source = $this->sources->get($sourceId);
        if (!$source || !in_array($sourceId, (array) $settings['sources'], true) || !$ids) {
            return;
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($source->kind() === SourceInterface::PRODUCTS && $sourceId === 'product') {
            $this->pending->wait($this->withVariants(array_map('intval', $ids)));
        }
        $facets = array_values(array_intersect(
            (array) $settings['facets'],
            array_column($this->site->facets([$sourceId]), 'attribute')
        ));

        foreach ($this->site->languages($store) as $language) {
            try {
                $index = $this->credentials->index((int) $store->getId(), $sourceId, $language['code']);
            } catch (BitlaneError $error) {
                if ($error->kind === 'no_index') {
                    continue;
                }
                throw $error;
            }
            $view = $this->storeManager->getStore($language['store_view']);
            if (!$view instanceof Store) {
                continue;
            }
            $context = new Context(
                $store,
                $view,
                $language['code'],
                $language['currency'],
                $facets,
                (string) $settings['product_documents']
            );
            try {
                $documents = $this->documents($source, $ids, $context, $run);
                $this->meilisearch->add($index, $documents);
                if (!$full) {
                    $this->deleteOthers($source, $index, $ids, array_column($documents, 'id'));
                }
            } catch (BitlaneError $error) {
                // The write key may have been replaced: the next try reads it again.
                if ($error->status === 401 || $error->status === 403) {
                    $this->credentials->forget((int) $store->getId());
                }
                throw $error;
            }
        }
    }

    /**
     * The items' documents as they're sent: the run, and only the picked filters.
     *
     * @param SourceInterface $source
     * @param list<string> $ids
     * @param Context $context
     * @param string $run
     * @return list<array<string,mixed>>
     */
    private function documents(SourceInterface $source, array $ids, Context $context, string $run): array
    {
        $this->emulation->startEnvironmentEmulation((int) $context->storeView->getId(), Area::AREA_FRONTEND, true);
        try {
            $byItem = $source->documents($ids, $context);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        $products = $source->kind() === SourceInterface::PRODUCTS;
        $picked = array_flip($context->facets);
        $documents = [];
        foreach ($ids as $item) {
            $itemDocuments = array_values(array_filter($byItem[$item] ?? [], 'is_array'));
            foreach ($products ? $itemDocuments : array_slice($itemDocuments, 0, 1) as $document) {
                $document['id'] = $products ? (string) ($document['id'] ?? $item) : $item;
                if ($products) {
                    $document['parent_id'] = (string) ($document['parent_id'] ?? $item);
                }
                foreach (['facets', 'product_facets', 'available'] as $field) {
                    if (isset($document[$field])) {
                        $document[$field] = (object) array_intersect_key((array) $document[$field], $picked);
                    }
                }
                $document['sync_run'] = $run;
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Deletes what the items had and no longer have; most calls are a read, not a write.
     *
     * @param SourceInterface $source
     * @param array<string,string> $index
     * @param list<string> $ids
     * @param list<string> $kept the documents just sent
     * @return void
     */
    private function deleteOthers(SourceInterface $source, array $index, array $ids, array $kept): void
    {
        $products = $source->kind() === SourceInterface::PRODUCTS;
        $gone = $products ? $ids : array_values(array_diff($ids, $kept));
        if (!$gone) {
            return;
        }
        if ($products) {
            $quoted = array_map(static fn ($item) => (string) json_encode($item), $ids);
            $existing = $this->meilisearch->documentIds($index, 'parent_id IN [' . implode(', ', $quoted) . ']');
        } else {
            $existing = $this->meilisearch->present($index, false, $gone)['items'];
        }
        $this->meilisearch->delete($index, array_values(array_diff($existing, $kept)));
    }

    /**
     * Products and their configurables' children, whose prices and stock their documents read.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function withVariants(array $ids): array
    {
        $db = $this->resource->getConnection();
        $children = $db->fetchCol(
            $db->select()->from($this->resource->getTableName('catalog_product_super_link'), 'product_id')
                ->where('parent_id IN (?)', $ids)
        );

        return array_values(array_unique(array_merge($ids, array_map('intval', $children))));
    }
}
