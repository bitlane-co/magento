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

use Bitlane\Search\Api\SourceInterface;
use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Source\SourcePool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;

/**
 * The daily check that each index matches its source, as the plugins': what no indexer saw.
 *
 * Indexers switched to "Update on Save", jobs that gave up, data written past
 * Magento's triggers. Each chosen source's ids are walked 1,000 at a time in
 * each store view, and Meilisearch says which the index has and when their
 * documents say each changed (modified_at): missing items, and items changed
 * since, sync like any change. If the index then holds more documents than
 * the walk found, some are of items gone, and the source's full sync sweeps
 * them. Ten batches a job; a source in a full sync is skipped. It also checks
 * the store's settings and its indexers' mode.
 */
class Check implements HandlerInterface
{
    public const JOB = 'check';

    /**
     * @param SourcePool $sources
     * @param Settings $settings
     * @param Site $site
     * @param SyncState $state
     * @param Credentials $credentials
     * @param Meilisearch $meilisearch
     * @param FullSync $full
     * @param Changes $changes
     * @param StoreSettings $storeSettings
     * @param IndexerModes $indexerModes
     * @param Jobs $jobs
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly SourcePool $sources,
        private readonly Settings $settings,
        private readonly Site $site,
        private readonly SyncState $state,
        private readonly Credentials $credentials,
        private readonly Meilisearch $meilisearch,
        private readonly FullSync $full,
        private readonly Changes $changes,
        private readonly StoreSettings $storeSettings,
        private readonly IndexerModes $indexerModes,
        private readonly Jobs $jobs,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Queues the next check: the next 2 AM in the store's timezone, at a random minute of that hour.
     *
     * Stores don't all check at once.
     *
     * @param Group $store
     * @return void
     */
    public function schedule(Group $store): void
    {
        $timezone = (string) $this->config->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            (int) $store->getDefaultStoreId()
        );
        $at = new \DateTimeImmutable('today 02:00', new \DateTimeZone($timezone ?: 'UTC'));
        if ($at->getTimestamp() <= time()) {
            $at = $at->modify('+1 day');
        }
        $this->jobs->push(
            (int) $store->getId(),
            self::JOB,
            [],
            $at->getTimestamp() - time() + random_int(0, 59) * 60,
            'check:' . $at->format('Ymd')
        );
    }

    /**
     * The check: without arguments it starts; with them, it's a batch of a source in a store view.
     *
     * @param int $storeId
     * @param array<mixed> $args the source, the language, the last id checked, documents found so far
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        $store = $this->site->store($storeId);
        if (!$store) {
            return;
        }
        if (!$args) {
            $this->start($store);

            return;
        }
        [$source, $language, $after, $documents] = $args + ['', '', '', 0];
        $this->batch($store, (string) $source, (string) $language, (string) $after, (int) $documents);
    }

    /**
     * Tomorrow's check, the indexers' mode, the store's settings, then a walk of each source in each store view.
     *
     * @param Group $store
     * @return void
     */
    private function start(Group $store): void
    {
        $this->schedule($store);
        $this->indexerModes->schedule();
        $this->storeSettings->check($store);
        foreach ((array) $this->settings->get($store)['sources'] as $source) {
            foreach ($this->site->languages($store) as $language) {
                $this->jobs->push((int) $store->getId(), self::JOB, [$source, $language['code'], '', 0]);
            }
        }
    }

    /**
     * Up to ten batches of a source in a store view, then the next job.
     *
     * At the end, the index's documents against those the walk found.
     *
     * @param Group $store
     * @param string $sourceId
     * @param string $language
     * @param string $after
     * @param int $documents
     * @return void
     */
    private function batch(Group $store, string $sourceId, string $language, string $after, int $documents): void
    {
        $storeId = (int) $store->getId();
        $source = $this->sources->get($sourceId);
        $state = $this->state->source($storeId, $sourceId);
        if (!$source || !in_array($sourceId, (array) $this->settings->get($store)['sources'], true)
            || (isset($state['started_at']) && empty($state['finished_at']))
        ) {
            return;
        }
        $index = $this->credentials->index($storeId, $sourceId, $language);
        for ($i = 0; $i < 10; ++$i) {
            $ids = $source->ids($store, $after, 1000);
            if (!$ids) {
                if ($this->meilisearch->count($index) > $documents) {
                    $this->full->start($store, $sourceId);
                }

                return;
            }
            $present = $this->meilisearch->present($index, $source->kind() === SourceInterface::PRODUCTS, $ids);
            $stale = [];
            foreach ($source->modified($store, $present['items']) as $item => $changed) {
                if ($changed > ($present['modified'][(string) $item] ?? 0)) {
                    $stale[] = (string) $item;
                }
            }
            $missing = array_values(array_diff($ids, $present['items']));
            array_push($missing, ...$stale);
            $this->changes->queue($store, $sourceId, $missing);
            $documents += $present['documents'];
            $after = (string) end($ids);
        }
        $this->jobs->push($storeId, self::JOB, [$sourceId, $language, $after, $documents]);
    }
}
