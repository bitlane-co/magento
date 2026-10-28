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

namespace Bitlane\Search\Model\Storefront;

use Bitlane\Search\Api\StorefrontInterface;
use Bitlane\Search\Model\Connection\BitlaneApi;
use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\State;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

/**
 * The search widget on the storefront (docs/magento-plan.md MG15, MG16).
 *
 * Pages never call Bitlane: each store's active widgets and their loader
 * are kept in bitlane_state, from the store's details whenever the Bitlane
 * screen loads them and every hour (job storefront), and each store view's
 * pages load the widget for its language. A change empties the full page
 * cache (Varnish's too), since pages carry the snippet. With Magento's
 * cookie restriction mode, tracking waits for the shopper's consent.
 */
class Storefront implements StorefrontInterface, HandlerInterface
{
    public const JOB = 'storefront';

    /**
     * @param State $state
     * @param BitlaneApi $api
     * @param ConnectionStore $connection
     * @param Jobs $jobs
     * @param Site $site
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypes
     */
    public function __construct(
        private readonly State $state,
        private readonly BitlaneApi $api,
        private readonly ConnectionStore $connection,
        private readonly Jobs $jobs,
        private readonly Site $site,
        private readonly ScopeConfigInterface $config,
        private readonly TypeListInterface $cacheTypes
    ) {
    }

    /**
     * What's kept for a store: the merchant's switch and its active widgets.
     *
     * @param int $storeId
     * @return array<string,mixed> enabled; widgets, each its id, title, languages and loader_url
     * @phpstan-return array{
     *     enabled: bool,
     *     widgets: list<array{id: string, title: string, languages: list<string>, loader_url: string}>
     * }
     */
    public function get(int $storeId): array
    {
        $stored = $this->state->json('storefront', $storeId);
        $widgets = [];
        foreach ((array) ($stored['widgets'] ?? []) as $widget) {
            $widgets[] = [
                'id' => (string) ($widget['id'] ?? ''),
                'title' => (string) ($widget['title'] ?? ''),
                'languages' => array_values(array_map('strval', (array) ($widget['languages'] ?? []))),
                'loader_url' => (string) ($widget['loader_url'] ?? ''),
            ];
        }

        return ['enabled' => (bool) ($stored['enabled'] ?? true), 'widgets' => $widgets];
    }

    /**
     * The merchant's switch: the widget on the store's pages or not.
     *
     * @param Group $store
     * @param bool $enabled
     * @return void
     */
    public function configure(Group $store, bool $enabled): void
    {
        $this->save((int) $store->getId(), ['enabled' => $enabled]);
    }

    /**
     * Keeps the store's active widgets from its details, with their loader, and their hourly refresh.
     *
     * @param Group $store
     * @param array<mixed> $integration GET /api/integrations/:id
     * @return void
     */
    public function remember(Group $store, array $integration): void
    {
        $widgets = [];
        foreach ((array) ($integration['widgets'] ?? []) as $widget) {
            $snippet = (string) ($widget['install_snippet'] ?? '');
            if (($widget['status'] ?? '') !== 'active'
                || !preg_match('/<script[^>]+src="(https:\/\/[^"]+)"/', $snippet, $match)
            ) {
                continue;
            }
            $widgets[] = [
                'id' => (string) $widget['id'],
                'title' => (string) ($widget['title'] ?? ''),
                'languages' => array_values(array_filter(array_map(
                    static fn ($language) => (string) ($language['language_code'] ?? ''),
                    (array) ($widget['settings']['languages'] ?? [])
                ))),
                'loader_url' => $match[1],
            ];
        }
        $this->save((int) $store->getId(), ['widgets' => $widgets]);
        $this->jobs->push((int) $store->getId(), self::JOB, [], 3600, self::JOB);
    }

    /**
     * The hourly refresh: widgets may have been switched off or on in Bitlane.
     *
     * Bitlane being unreachable keeps what's known (the job retries); a store
     * Bitlane no longer has loses its widgets.
     *
     * @param int $storeId
     * @param array<mixed> $args
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        $store = $this->site->store($storeId);
        $id = $this->connection->integrationId($storeId);
        if (!$store || !$id || !$this->connection->connected()) {
            $this->save($storeId, ['widgets' => []]);

            return;
        }
        try {
            $this->remember($store, $this->api->request('GET', '/api/integrations/' . rawurlencode($id)));
        } catch (BitlaneError $error) {
            if ($error->status !== 404) {
                throw $error;
            }
            $this->save($storeId, ['widgets' => []]);
        }
    }

    /**
     * The store disconnected: no widget on its pages, cached ones included, and its switch forgotten.
     *
     * @param Group $store
     * @return void
     */
    public function forget(Group $store): void
    {
        $this->save((int) $store->getId(), ['enabled' => true, 'widgets' => []]);
        $this->state->set('storefront', null, (int) $store->getId());
    }

    /**
     * @inheritdoc
     */
    public function isShown(Store $view, string $page): bool
    {
        return !str_starts_with($page, 'checkout_') && $this->widget($view) !== null;
    }

    /**
     * The store view's BitlaneWidgetConfig: the widget, the view's language, and tracking off until consent.
     *
     * Null when its pages show no widget.
     *
     * @param Store $view
     * @return array{config: array<string, mixed>, loader_url: string, consent: bool}|null
     */
    public function config(Store $view): ?array
    {
        $widget = $this->widget($view);
        if ($widget === null) {
            return null;
        }
        // Magento's cookie restriction mode: no counting before the shopper allows cookies.
        $consent = $this->config->isSetFlag(
            'web/cookie/cookie_restriction',
            ScopeInterface::SCOPE_STORE,
            (int) $view->getId()
        );
        $config = ['id' => $widget['id'], 'language' => $this->site->languageCode((int) $view->getId())];
        if ($consent) {
            $config['tracking'] = false;
        }

        return ['config' => $config, 'loader_url' => $widget['loader_url'], 'consent' => $consent];
    }

    /**
     * The widget a store view's pages load: the first active one with its language, else the first.
     *
     * @param Store $view
     * @return array{id: string, title: string, languages: list<string>, loader_url: string}|null
     */
    private function widget(Store $view): ?array
    {
        $stored = $this->get((int) $view->getStoreGroupId());
        if (!$stored['enabled'] || !$stored['widgets']) {
            return null;
        }
        $language = strtolower($this->site->languageCode((int) $view->getId()));
        foreach ($stored['widgets'] as $widget) {
            if (in_array($language, array_map('strtolower', $widget['languages']), true)) {
                return $widget;
            }
        }

        return $stored['widgets'][0];
    }

    /**
     * Merges changes into what's kept; pages that carried the old snippet leave the full page cache.
     *
     * @param int $storeId
     * @param array<string,mixed> $changes
     * @return void
     */
    private function save(int $storeId, array $changes): void
    {
        $old = $this->get($storeId);
        $new = array_merge($old, $changes);
        if ($new == $old && $this->state->get('storefront', $storeId) !== null) {
            return;
        }
        $this->state->setJson('storefront', $new, $storeId);
        // Magento_PageCache's type; cleaning it purges Varnish too.
        $this->cacheTypes->cleanType('full_page');
    }
}
