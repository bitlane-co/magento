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
use Bitlane\Search\Model\Support\BitlaneError;
use Magento\Store\Model\Group;

/**
 * A source's full sync in a store, as jobs (type full_sync) one after another.
 *
 * Each a batch of items (100 products, 200 of anything else) written in
 * every store view, its arguments the cursor (source, run, last id), so a
 * retry redoes exactly that batch. Documents are stamped with a new run; once
 * the source's items are all sent, each store view's index loses the
 * documents of another run, which removes what's gone since while the index
 * stays searchable throughout (the plugins' W6).
 */
class FullSync implements HandlerInterface
{
    public const JOB = 'full_sync';

    /**
     * @param Indexer $indexer
     * @param SourcePool $sources
     * @param Settings $settings
     * @param Site $site
     * @param SyncState $state
     * @param Credentials $credentials
     * @param Meilisearch $meilisearch
     * @param Jobs $jobs
     */
    public function __construct(
        private readonly Indexer $indexer,
        private readonly SourcePool $sources,
        private readonly Settings $settings,
        private readonly Site $site,
        private readonly SyncState $state,
        private readonly Credentials $credentials,
        private readonly Meilisearch $meilisearch,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * Starts a source's full sync; one still running stops (its jobs see a newer run and end).
     *
     * @param Group $store
     * @param string $source
     * @return string the run
     */
    public function start(Group $store, string $source): string
    {
        $storeId = (int) $store->getId();
        $run = $this->state->newRun();
        $total = 0;
        foreach ($this->site->sources($store) as $known) {
            if ($known['id'] === $source) {
                $total = $known['count'];
            }
        }
        $this->state->updateSource($storeId, $source, [
            'run' => $run,
            'done' => 0,
            'total' => $total,
            'started_at' => time(),
            'finished_at' => null,
        ]);
        $this->jobs->push($storeId, self::JOB, [$source, $run, '']);

        return $run;
    }

    /**
     * One batch, then the next is queued.
     *
     * @param int $storeId
     * @param array<mixed> $args the source, the run, the last id done
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        [$sourceId, $run, $after] = array_map('strval', $args + ['', '', '']);
        $store = $this->site->store($storeId);
        $state = $this->state->source($storeId, $sourceId);
        $source = $this->sources->get($sourceId);
        if (!$store || !$source || ($state['run'] ?? '') !== $run || !empty($state['finished_at'])
            || !in_array($sourceId, (array) $this->settings->get($store)['sources'], true)
        ) {
            return;
        }

        $ids = $source->ids($store, $after, $source->kind() === SourceInterface::PRODUCTS ? 100 : 200);
        if (!$ids) {
            $this->sweep($store, $sourceId, $run);
            $this->state->updateSource($storeId, $sourceId, ['finished_at' => time()]);

            return;
        }

        $this->indexer->sync($store, $sourceId, $ids, $run, true);
        $this->state->updateSource($storeId, $sourceId, ['done' => (int) ($state['done'] ?? 0) + count($ids)]);
        $this->jobs->push($storeId, self::JOB, [$sourceId, $run, (string) end($ids)]);
    }

    /**
     * Deletes the source's documents of other runs in each store view's index.
     *
     * @param Group $store
     * @param string $source
     * @param string $run
     * @return void
     */
    private function sweep(Group $store, string $source, string $run): void
    {
        foreach ($this->site->languages($store) as $language) {
            try {
                $index = $this->credentials->index((int) $store->getId(), $source, $language['code']);
            } catch (BitlaneError $error) {
                if ($error->kind === 'no_index') {
                    continue;
                }
                throw $error;
            }
            $this->meilisearch->deleteWhere($index, 'sync_run != "' . $run . '"');
        }
    }
}
