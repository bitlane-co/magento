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

use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\State;

/**
 * Where a store's sync is: a small value of the state table, the same size for any catalogue.
 *
 * Items wait in bitlane_job; a full sync's cursor travels in its job's
 * arguments. Per source:
 * - run: stamped on every document of the source written (sync_run). A full
 *   sync starts a new one and, at its end, deletes the source's documents with
 *   another, so what changed meanwhile survives it;
 * - setup: the source's setup it was last synced in full for (Settings::hash());
 * - done, total, started_at, finished_at: its last full sync;
 * the last error, until a sync succeeds; and the engine the store's indexes
 * are on (a new one means syncing everything again, Sync::ready()).
 */
class SyncState
{
    /**
     * @param State $state
     */
    public function __construct(private readonly State $state)
    {
    }

    /**
     * The store's sync state.
     *
     * @param int $storeId
     * @return array<string,mixed>
     */
    public function get(int $storeId): array
    {
        return $this->state->json('sync', $storeId);
    }

    /**
     * The engine the store's indexes are on, as Bitlane last said; empty before.
     *
     * @param int $storeId
     * @return string
     */
    public function engine(int $storeId): string
    {
        return (string) ($this->get($storeId)['engine'] ?? '');
    }

    /**
     * Notes the engine the store's indexes are on.
     *
     * @param int $storeId
     * @param string $engineId
     * @return void
     */
    public function setEngine(int $storeId, string $engineId): void
    {
        $state = $this->get($storeId);
        $state['engine'] = $engineId;
        $this->state->setJson('sync', $state, $storeId);
    }

    /**
     * A source's state.
     *
     * @param int $storeId
     * @param string $source
     * @return array<string,mixed>
     */
    public function source(int $storeId, string $source): array
    {
        return (array) ($this->get($storeId)['sources'][$source] ?? []);
    }

    /**
     * Merges changes into a source's state; a null value removes the key, null for $changes the source.
     *
     * @param int $storeId
     * @param string $source
     * @param array<string,mixed>|null $changes
     * @return void
     */
    public function updateSource(int $storeId, string $source, ?array $changes): void
    {
        $state = $this->get($storeId);
        if ($changes === null) {
            unset($state['sources'][$source]);
        } else {
            $state['sources'][$source] = array_filter(
                array_merge((array) ($state['sources'][$source] ?? []), $changes),
                static fn ($value) => $value !== null
            );
        }
        $this->state->setJson('sync', $state, $storeId);
    }

    /**
     * A source's current run, started when it has none.
     *
     * @param int $storeId
     * @param string $source
     * @return string
     */
    public function run(int $storeId, string $source): string
    {
        $run = (string) ($this->source($storeId, $source)['run'] ?? '');
        if ($run === '') {
            $run = $this->newRun();
            $this->updateSource($storeId, $source, ['run' => $run]);
        }

        return $run;
    }

    /**
     * A run id: short, and fine in a Meilisearch filter.
     *
     * @return string
     */
    public function newRun(): string
    {
        return gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Notes a failure, or clears it after a success.
     *
     * @param int $storeId
     * @param BitlaneError|null $error
     * @return void
     */
    public function error(int $storeId, ?BitlaneError $error): void
    {
        $state = $this->get($storeId);
        if ($error === null && !isset($state['error'])) {
            return;
        }
        if ($error) {
            $state['error'] = ['message' => $error->getMessage(), 'at' => time()];
        } else {
            unset($state['error']);
        }
        $this->state->setJson('sync', $state, $storeId);
    }
}
