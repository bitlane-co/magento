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

use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Settings;
use Magento\Store\Model\Group;

/**
 * A store's sync as the Bitlane screen and the console see it.
 *
 * When a source syncs in full (Bitlane made a setup ready that changed it;
 * Resync), and where each is.
 */
class Sync
{
    /**
     * @param FullSync $full
     * @param Check $check
     * @param StoreSettings $storeSettings
     * @param SyncState $state
     * @param Settings $settings
     * @param Credentials $credentials
     * @param Jobs $jobs
     */
    public function __construct(
        private readonly FullSync $full,
        private readonly Check $check,
        private readonly StoreSettings $storeSettings,
        private readonly SyncState $state,
        private readonly Settings $settings,
        private readonly Credentials $credentials,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * Bitlane made the store's setup ready: each source the setup changed syncs in full.
     *
     * A source is new, has other filters, languages, or for products another
     * mode; every source syncs when the store moved to another engine
     * ($engineId, the site's in Bitlane: the new one's indexes start empty).
     * Sources no longer chosen are forgotten (Bitlane deleted their indexes).
     * The daily check runs from then on.
     *
     * @param Group $store
     * @param string $engineId
     * @return void
     */
    public function ready(Group $store, string $engineId = ''): void
    {
        $storeId = (int) $store->getId();
        // Once a day: a check already queued is kept.
        $this->check->schedule($store);
        // Settings that change every document: at most hourly.
        $this->storeSettings->check($store, false);
        $chosen = (array) $this->settings->get($store)['sources'];
        $moved = $engineId !== '' && !in_array($this->state->engine($storeId), ['', $engineId], true);
        if ($engineId !== '' && $this->state->engine($storeId) !== $engineId) {
            $this->state->setEngine($storeId, $engineId);
        }
        $changed = $moved ? $chosen : array_values(array_filter(
            $chosen,
            fn ($source) => ($this->state->source($storeId, $source)['setup'] ?? '')
                !== $this->settings->hash($store, $source)
        ));
        foreach (array_diff(array_keys((array) ($this->state->get($storeId)['sources'] ?? [])), $chosen) as $removed) {
            $this->state->updateSource($storeId, (string) $removed, null);
        }
        if (!$changed) {
            return;
        }
        $this->credentials->forget($storeId);
        $this->storeSettings->remember($store);
        foreach ($changed as $source) {
            $this->full->start($store, $source);
            $this->state->updateSource($storeId, $source, ['setup' => $this->settings->hash($store, $source)]);
        }
    }

    /**
     * Syncs a source again, or every chosen one.
     *
     * @param Group $store
     * @param string|null $source
     * @return list<string> the sources whose full sync started
     */
    public function resync(Group $store, ?string $source = null): array
    {
        $chosen = (array) $this->settings->get($store)['sources'];
        $sources = array_values($source === null ? $chosen : array_intersect([$source], $chosen));
        $this->credentials->forget((int) $store->getId());
        foreach ($sources as $id) {
            $this->full->start($store, $id);
        }

        return $sources;
    }

    /**
     * Where each chosen source is, the changes waiting, and the last error.
     *
     * @param Group $store
     * @return array<string,mixed>
     */
    public function summary(Group $store): array
    {
        $storeId = (int) $store->getId();
        $state = $this->state->get($storeId);
        $sources = [];
        foreach ((array) $this->settings->get($store)['sources'] as $id) {
            $source = (array) ($state['sources'][$id] ?? []);
            $sources[] = [
                'id' => $id,
                'running' => isset($source['started_at']) && empty($source['finished_at']),
                'done' => (int) ($source['done'] ?? 0),
                'total' => (int) ($source['total'] ?? 0),
                'started_at' => isset($source['started_at']) ? (int) $source['started_at'] : null,
                'finished_at' => isset($source['finished_at']) ? (int) $source['finished_at'] : null,
            ];
        }

        return [
            'sources' => $sources,
            'queued' => $this->jobs->waiting($storeId, false, [Changes::JOB, ProductsOf::JOB]),
            'error' => $state['error'] ?? null,
        ];
    }
}
