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

use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Support\State;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;

/**
 * Store settings that change every document, which no indexer announces (docs/magento-plan.md Part 3).
 *
 * Prices and stock as guests see them change every product's, URLs every
 * source's (the store's own address included). Their hash is compared on the
 * Bitlane screen (at most hourly) and by the daily check; a change starts the
 * full syncs it needs, and a new address or name goes to Bitlane too.
 */
class StoreSettings
{
    /**
     * What every product's documents depend on, per store view.
     */
    private const PRODUCTS = [
        'catalog/price/scope',
        'tax/display/type',
        'tax/calculation/price_includes_tax',
        'tax/defaults/country',
        'currency/options/base',
        'currency/options/default',
        'cataloginventory/options/show_out_of_stock',
        'cataloginventory/item_options/backorders',
    ];

    /**
     * What every document's link depends on, per store view.
     */
    private const LINKS = [
        'web/unsecure/base_link_url',
        'web/secure/base_link_url',
        'web/url/use_store',
        'web/seo/use_rewrites',
        'catalog/seo/product_url_suffix',
        'catalog/seo/category_url_suffix',
    ];

    /**
     * @param State $state
     * @param Settings $settings
     * @param FullSync $full
     * @param Site $site
     * @param SiteDetails $siteDetails
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly State $state,
        private readonly Settings $settings,
        private readonly FullSync $full,
        private readonly Site $site,
        private readonly SiteDetails $siteDetails,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Starts the full syncs the store's changed settings need; at most hourly unless $now.
     *
     * @param Group $store
     * @param bool $now
     * @return void
     */
    public function check(Group $store, bool $now = true): void
    {
        $storeId = (int) $store->getId();
        $kept = $this->state->json('store_settings', $storeId);
        if (!$now && (int) ($kept['at'] ?? 0) > time() - 3600) {
            return;
        }
        $this->siteDetails->check($store);
        $hashes = $this->hashes($store);
        $this->state->setJson('store_settings', $hashes + ['at' => time()], $storeId);
        if (!isset($kept['products'])) {
            return;
        }
        $sources = (array) $this->settings->get($store)['sources'];
        if ($kept['links'] !== $hashes['links']) {
            foreach ($sources as $source) {
                $this->full->start($store, $source);
            }
        } elseif ($kept['products'] !== $hashes['products'] && in_array('product', $sources, true)) {
            $this->full->start($store, 'product');
        }
    }

    /**
     * Notes the settings as synced: the store's documents were just made for them.
     *
     * @param Group $store
     * @return void
     */
    public function remember(Group $store): void
    {
        $this->state->setJson('store_settings', $this->hashes($store) + ['at' => time()], (int) $store->getId());
    }

    /**
     * The hashes of the products' and the links' settings across the store's views.
     *
     * @param Group $store
     * @return array{products: string, links: string}
     */
    private function hashes(Group $store): array
    {
        $values = ['products' => [], 'links' => [$this->site->url($store)]];
        foreach ($this->site->languages($store) as $language) {
            foreach (['products' => self::PRODUCTS, 'links' => self::LINKS] as $kind => $paths) {
                foreach ($paths as $path) {
                    $values[$kind][] = $this->config->getValue(
                        $path,
                        ScopeInterface::SCOPE_STORE,
                        $language['store_view']
                    );
                }
            }
        }

        return [
            'products' => hash('sha256', (string) json_encode($values['products'])),
            'links' => hash('sha256', (string) json_encode($values['links'])),
        ];
    }
}
