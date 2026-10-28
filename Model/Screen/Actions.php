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

namespace Bitlane\Search\Model\Screen;

use Bitlane\Search\Model\Connection\BitlaneApi;
use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Connection\OAuthClient;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Queue\Runner;
use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Storefront\Storefront;
use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\State;
use Bitlane\Search\Model\Support\Updates;
use Bitlane\Search\Model\Sync\IndexerModes;
use Bitlane\Search\Model\Sync\Sync;
use Magento\Store\Model\Group;

/**
 * What the Bitlane screen asks for (Controller\Adminhtml\Action\Index), for
 * one store: the grant is the install's, each store is a site of its own in
 * Bitlane (docs/magento-plan.md MG4). Tokens and keys never leave the server.
 */
class Actions
{
    /**
     * @param ConnectionStore $connection
     * @param OAuthClient $oauth
     * @param BitlaneApi $api
     * @param Site $site
     * @param Settings $settings
     * @param State $state
     * @param Runner $runner
     * @param Jobs $jobs
     * @param Sync $sync
     * @param IndexerModes $indexerModes
     * @param Storefront $storefront
     * @param Updates $updates
     */
    public function __construct(
        private readonly ConnectionStore $connection,
        private readonly OAuthClient $oauth,
        private readonly BitlaneApi $api,
        private readonly Site $site,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Runner $runner,
        private readonly Jobs $jobs,
        private readonly Sync $sync,
        private readonly IndexerModes $indexerModes,
        private readonly Storefront $storefront,
        private readonly Updates $updates
    ) {
    }

    /**
     * The store an action is for: the one asked for, else the install's only store.
     *
     * Null when the admin has to pick one.
     *
     * @param int|null $storeId
     * @return Group|null
     */
    public function store(?int $storeId): ?Group
    {
        $stores = $this->site->stores();
        if ($storeId) {
            return $this->site->store($storeId);
        }

        return count($stores) === 1 ? $stores[0] : null;
    }

    /**
     * Everything the screen shows for a store; without one, the stores to pick from.
     *
     * @param Group|null $store
     * @return array<string, mixed>
     */
    public function status(?Group $store): array
    {
        $storeId = $store ? (int) $store->getId() : 0;
        $connection = $this->connection->get();
        $integration = null;
        $error = null;

        $integrationId = $store ? $this->connection->integrationId($storeId) : null;
        if (!empty($connection['refresh_token']) && $integrationId) {
            try {
                $integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
            } catch (BitlaneError $e) {
                if ($e->status === 404) {
                    // The store belongs to another connection now: it connects again.
                    $this->connection->setIntegrationId($storeId, null);
                } else {
                    $error = $e->getMessage();
                }
            }
            // A refused token may have ended the connection meanwhile.
            $connection = $this->connection->get();
            $integrationId = $this->connection->integrationId($storeId);
        }
        $connected = !empty($connection['refresh_token']);
        $ready = $connected && $store && ($integration['setup_state'] ?? '') === 'ready';
        if ($ready) {
            $this->sync->ready($store, (string) ($integration['engine_id'] ?? ''));
            // Its widgets as Bitlane has them now, for the storefront.
            $this->storefront->remember($store, $integration);
        }

        return [
            'connected' => $connected,
            'revoked' => !empty($connection['revoked']),
            'needs_project' => $connected && $store && !$integrationId,
            'integration' => $integration,
            'error' => $error,
            'app_url' => $connected ? $this->oauth->appUrl() : null,
            'store' => $store ? $this->describe($store) : null,
            'stores' => array_map(fn (Group $group) => [
                'id' => (int) $group->getId(),
                'name' => $this->site->name($group),
                'url' => $this->site->url($group),
                'connected' => $connected && $this->connection->integrationId((int) $group->getId()) !== null,
            ], $this->site->stores()),
            'settings' => $store ? $this->settings->get($store) : null,
            'sync' => $ready ? $this->sync->summary($store) : null,
            'storefront' => $ready ? $this->storefront->get($storeId) : null,
            // A newer release of the extension, to install with Composer or the zip.
            'update' => $this->updates->available(),
            // The sync's indexers left on "Update on Save", which see no changes.
            'indexers_on_save' => $connected ? $this->indexerModes->onSave() : [],
            // The cron that runs the jobs, and whether it does.
            'runner' => $connected ? [
                'cron' => $this->runner->cronLine(),
                'command' => $this->runner->command(),
                'last_run' => $this->runner->lastRun() ?: null,
                'waiting_since' => $this->runner->waitingSince() ?: null,
                // Jobs due for a quarter of an hour: no cron runs them.
                'late' => $this->runner->waitingSince() > 0 && $this->runner->waitingSince() < time() - 900,
                'due' => $store ? $this->jobs->waiting($storeId, true) : 0,
                'failed' => $store ? $this->jobs->failed($storeId) : 0,
            ] : null,
        ];
    }

    /**
     * The storefront's switch (the widget on the store's pages), then the status.
     *
     * @param Group $store
     * @param bool $enabled
     * @return array<string, mixed>
     */
    public function storefront(Group $store, bool $enabled): array
    {
        $this->storefront->configure($store, $enabled);

        return $this->status($store);
    }

    /**
     * Syncs a source again, or every chosen one, then answers the status.
     *
     * @param Group $store
     * @param string|null $source
     * @return array<string, mixed>
     */
    public function resync(Group $store, ?string $source): array
    {
        $this->sync->resync($store, $source);

        return $this->status($store);
    }

    /**
     * Puts the sync's indexers back on "Update by Schedule", then answers the status.
     *
     * @param Group $store
     * @return array<string, mixed>
     */
    public function schedule(Group $store): array
    {
        $this->indexerModes->schedule();

        return $this->status($store);
    }

    /**
     * Runs due jobs for a few seconds, then answers the status.
     *
     * The screen does it while it's open, so a store whose cron doesn't run still syncs.
     *
     * @param Group $store
     * @return array<string, mixed>
     */
    public function run(Group $store): array
    {
        $this->runner->run(8);

        return $this->status($store);
    }

    /**
     * The store's jobs for the Jobs tab: waiting, running and failed (a week).
     *
     * @param Group $store
     * @return array<string, mixed>
     */
    public function jobs(Group $store): array
    {
        $storeId = (int) $store->getId();

        return [
            'jobs' => $this->jobs->list($storeId),
            'waiting' => $this->jobs->waiting($storeId),
            'failed' => $this->jobs->failed($storeId),
        ];
    }

    /**
     * Runs a failed job again, then answers the jobs.
     *
     * @param Group $store
     * @param int $id
     * @return array<string, mixed>
     */
    public function jobAgain(Group $store, int $id): array
    {
        $this->jobs->again((int) $store->getId(), $id);

        return $this->jobs($store);
    }

    /**
     * Deletes a job that isn't running, then answers the jobs.
     *
     * @param Group $store
     * @param int $id
     * @return array<string, mixed>
     */
    public function jobDelete(Group $store, int $id): array
    {
        $this->jobs->delete((int) $store->getId(), $id);

        return $this->jobs($store);
    }

    /**
     * The consent page's URL, to start a connection from this store.
     *
     * @param Group $store
     * @param int $adminUserId
     * @return string
     */
    public function authorize(Group $store, int $adminUserId): string
    {
        return $this->oauth->authorizeUrl($adminUserId, (int) $store->getId());
    }

    /**
     * The user's projects, for a store without a site in Bitlane yet.
     *
     * A grant on one project lists that one only.
     *
     * @return list<array{id: string, title: string}>
     */
    public function projects(): array
    {
        $projects = $this->api->request('GET', '/api/projects');

        return array_map(static fn (array $project) => [
            'id' => (string) $project['id'],
            'title' => (string) ($project['title'] ?? $project['name'] ?? $project['id']),
        ], array_values($projects['data'] ?? []));
    }

    /**
     * Connects the store in Bitlane, in the grant's project or the given one.
     *
     * Its site is the grant's for its address, created or found.
     *
     * @param Group $store
     * @param string|null $projectId
     * @return void
     */
    public function connectStore(Group $store, ?string $projectId = null): void
    {
        $body = [
            'platform' => 'magento',
            'site_url' => $this->site->url($store),
            'site_name' => $this->site->name($store),
        ];
        if ($projectId) {
            $body['project_id'] = $projectId;
        }
        $integration = $this->api->request('POST', '/api/integrations', $body);
        $this->connection->setIntegrationId((int) $store->getId(), (string) $integration['id']);
    }

    /**
     * The project's engines, for the setup.
     *
     * @param Group $store
     * @return list<array{id: string, title: string, status: string}>
     */
    public function engines(Group $store): array
    {
        $project = $this->project($store);
        $engines = $this->api->request('GET', '/api/engines', null, $project ? ['project_id' => $project] : []);

        return array_map(static fn (array $engine) => [
            'id' => (string) $engine['id'],
            'title' => (string) $engine['title'],
            'status' => (string) $engine['status'],
        ], array_values($engines['data'] ?? []));
    }

    /**
     * Saves the store's setup and sends it to Bitlane.
     *
     * Bitlane sets the store up in the background: engine, indexes, keys and widget.
     *
     * @param Group $store
     * @param array<string,mixed> $input
     * @return array<string, mixed> the status
     */
    public function setup(Group $store, array $input): array
    {
        $integrationId = $this->connection->integrationId((int) $store->getId());
        if (!$integrationId) {
            throw new BitlaneError('not_connected', (string) __('Connect the store to Bitlane first.'), 409);
        }

        $kinds = array_column($this->site->sources($store), 'kind', 'id');
        $sources = array_values(array_intersect($this->strings($input['sources'] ?? []), array_keys($kinds)));
        if (!$sources) {
            throw new BitlaneError('no_sources', (string) __('Choose at least one kind of content to search.'), 400);
        }
        $outside = array_values(array_intersect($this->strings($input['outside_widget'] ?? []), $sources));
        $offered = array_column($this->site->facets($sources), 'sources', 'attribute');
        $facets = array_values(array_intersect($this->strings($input['facets'] ?? []), array_keys($offered)));
        $sorting = $this->sorting($sources, $kinds, is_array($input['sorting'] ?? null) ? $input['sorting'] : []);
        $engineId = is_string($input['engine_id'] ?? null) && $input['engine_id'] !== '' ? $input['engine_id'] : null;
        $selector = trim(strip_tags((string) ($input['search_selector'] ?? '')));
        $color = preg_match('/^#(?:[0-9a-f]{3}){1,2}$/i', (string) ($input['brand_color'] ?? ''))
            ? (string) $input['brand_color'] : '';
        $logo = (string) ($input['logo_url'] ?? '');
        $logo = filter_var($logo, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $logo) ? $logo : '';
        $documents = in_array($input['product_documents'] ?? null, Settings::PRODUCT_DOCUMENTS, true)
            ? (string) $input['product_documents'] : Settings::PER_VARIATION;

        $languages = $this->site->languages($store);
        $labels = [];
        foreach ($languages as $language) {
            foreach ($this->site->facets($sources, $language['store_view']) as $facet) {
                $labels[$facet['attribute']][$language['code']] = $facet['label'];
            }
        }

        $setup = [
            // Each store view with its own indexes, in its own currency, shown
            // as the store view shows prices.
            'languages' => array_map(fn (array $language) => array_filter([
                'code' => $language['code'],
                'currency' => $language['currency'],
                'format' => $this->site->currencyFormat($language['store_view'], $language['currency']),
            ]), $languages),
            // Each source has its own index; the widget searches those not
            // kept for the store's own use.
            'sources' => array_map(static fn (string $id) => [
                'id' => $id,
                'kind' => $kinds[$id],
                'widget' => !in_array($id, $outside, true),
                'sorting' => $sorting[$id],
            ], $sources),
            // In the order chosen (the widget shows them so), each a filter
            // of its sources' indexes only.
            'facets' => array_map(static fn (string $attribute) => [
                'attribute' => $attribute,
                'labels' => $labels[$attribute] ?? new \stdClass(),
                'sources' => $offered[$attribute],
            ], $facets),
        ];
        if ($selector !== '') {
            $setup['search_selector'] = $selector;
        }
        if ($engineId) {
            $setup['engine_id'] = $engineId;
        }
        // Bitlane uses them for a widget without colours or a logo only.
        $brand = array_filter(['primary' => $color, 'logo_url' => $logo]);
        if ($brand) {
            $setup['brand'] = $brand;
        }

        $this->api->request('PUT', '/api/integrations/' . rawurlencode($integrationId) . '/setup', $setup);
        $this->settings->save($store, [
            'engine_id' => $engineId,
            'sources' => $sources,
            'outside_widget' => $outside,
            'facets' => $facets,
            'sorting' => $sorting,
            'search_selector' => $selector,
            'brand_color' => $color,
            'logo_url' => $logo,
            'product_documents' => $documents,
            'languages' => array_column($languages, 'code'),
        ]);

        return $this->status($store);
    }

    /**
     * Disconnects the store: Bitlane deletes its keys; the engine, indexes
     * and documents stay. The store forgets everything about Bitlane, setup
     * choices included. The grant ends with the install's last connected
     * store.
     *
     * @param Group $store
     * @return void
     */
    public function disconnect(Group $store): void
    {
        $storeId = (int) $store->getId();
        $integrationId = $this->connection->integrationId($storeId);
        if ($integrationId) {
            try {
                $this->api->request('DELETE', '/api/integrations/' . rawurlencode($integrationId));
                // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- nothing to do but forget it.
            } catch (BitlaneError) {
                // Gone already, or Bitlane can't be reached: the store forgets it anyway.
            }
        }
        $this->storefront->forget($store);
        $this->state->forgetStore($storeId);
        $this->jobs->cancel($storeId);
        $others = array_filter(
            $this->site->stores(),
            fn (Group $group) => $this->connection->integrationId((int) $group->getId()) !== null
        );
        if (!$others) {
            $this->oauth->revoke();
        }
    }

    /**
     * The store as the screen shows it.
     *
     * @param Group $store
     * @return array<string, mixed>
     */
    private function describe(Group $store): array
    {
        $sources = $this->site->sources($store);

        return [
            'id' => (int) $store->getId(),
            'name' => $this->site->name($store),
            'url' => $this->site->url($store),
            'languages' => array_map(
                static fn (array $l) => ['code' => $l['code'], 'name' => $l['name'], 'currency' => $l['currency']],
                $this->site->languages($store)
            ),
            'sources' => $sources,
            'facets' => $this->site->facets(array_column($sources, 'id')),
            'sorting' => $this->site->sorting(),
            'logo_url' => $this->site->logoUrl($store),
        ];
    }

    /**
     * The project of the store's site, else the grant's.
     *
     * @param Group $store
     * @return string|null
     */
    private function project(Group $store): ?string
    {
        $integrationId = $this->connection->integrationId((int) $store->getId());
        if ($integrationId) {
            $integration = $this->api->request('GET', '/api/integrations/' . rawurlencode($integrationId));
            if (!empty($integration['project_id'])) {
                return (string) $integration['project_id'];
            }
        }
        $project = $this->connection->get()['project_id'] ?? null;

        return is_string($project) ? $project : null;
    }

    /**
     * Each source's sort choices, else its kind's defaults.
     *
     * Those asked for that its kind has, in their order.
     *
     * @param list<string> $sources
     * @param array<string,string> $kinds source => its kind
     * @param array<mixed> $chosen source => the choices asked for
     * @return array<string, list<string>>
     */
    private function sorting(array $sources, array $kinds, array $chosen): array
    {
        $offered = $this->site->sorting();
        $sorting = [];
        foreach ($sources as $source) {
            $kind = $offered[$kinds[$source]];
            $sorting[$source] = isset($chosen[$source]) && is_array($chosen[$source])
                ? array_values(array_intersect($this->strings($chosen[$source]), array_column($kind['choices'], 'id')))
                : $kind['defaults'];
        }

        return $sorting;
    }

    /**
     * The strings of a list from the screen.
     *
     * @param mixed $list
     * @return list<string>
     */
    private function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
