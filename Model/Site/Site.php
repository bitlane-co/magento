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

namespace Bitlane\Search\Model\Site;

use Bitlane\Search\Api\SourceInterface;
use Bitlane\Search\Model\Source\SourcePool;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A store (Magento's store group) as the setup screen offers it
 * (docs/magento-plan.md MG4, MG5, MG12, MG13): its address, its store views
 * as languages with their currencies, the content it can search with their
 * counts, and the filters each has. Every store of the install is a site of
 * its own in Bitlane.
 */
class Site
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $config
     * @param SourcePool $sources
     * @param AttributeCollectionFactory $attributes
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $config,
        private readonly SourcePool $sources,
        private readonly AttributeCollectionFactory $attributes
    ) {
    }

    /**
     * The install's stores, the admin's left out.
     *
     * @return list<Group>
     */
    public function stores(): array
    {
        return array_values(array_filter(
            $this->storeManager->getGroups(),
            static fn ($group) => $group instanceof Group && (int) $group->getWebsiteId() > 0
        ));
    }

    /**
     * A store of the install; null for one that doesn't exist.
     *
     * @param int $storeId
     * @return Group|null
     */
    public function store(int $storeId): ?Group
    {
        foreach ($this->stores() as $group) {
            if ((int) $group->getId() === $storeId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The store's address: its default store view's home, with the store
     * code when URLs carry it, without a trailing slash. Two stores of one
     * website are told apart by it.
     *
     * @param Group $store
     * @return string
     */
    public function url(Group $store): string
    {
        return rtrim($this->defaultView($store)->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/');
    }

    /**
     * The store's name, else its address's host.
     *
     * @param Group $store
     * @return string
     */
    public function name(Group $store): string
    {
        $name = trim((string) $store->getName());

        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the store's own URL's host.
        return $name !== '' ? $name : (string) parse_url($this->url($store), PHP_URL_HOST);
    }

    /**
     * The store's languages: its active store views, the default first, each
     * with its locale as a language code ("it_IT" is "it-IT") and its default
     * display currency. Views sharing a locale (one per currency) are one
     * language, the first one's (MG12).
     *
     * @param Group $store
     * @return list<array{store_view: int, code: string, name: string, currency: string}>
     */
    public function languages(Group $store): array
    {
        $default = $this->defaultView($store);
        $views = array_filter(
            $this->storeManager->getStores(),
            static fn ($view) => $view instanceof Store && $view->isActive()
                && (int) $view->getStoreGroupId() === (int) $store->getId()
        );
        $order = static fn (Store $view) => [(int) ($view->getId() !== $default->getId()), (int) $view->getSortOrder()];
        usort($views, static fn (Store $a, Store $b) => $order($a) <=> $order($b));

        $languages = [];
        foreach ($views as $view) {
            $code = $this->languageCode((int) $view->getId());
            $languages[strtolower($code)] ??= [
                'store_view' => (int) $view->getId(),
                'code' => $code,
                'name' => (string) $view->getName(),
                'currency' => (string) $view->getDefaultCurrencyCode(),
            ];
        }

        return array_values($languages);
    }

    /**
     * A store view's language as Bitlane knows it: its locale with a hyphen ("it-IT").
     *
     * @param int $storeViewId
     * @return string
     */
    public function languageCode(int $storeViewId): string
    {
        return str_replace('_', '-', $this->locale($storeViewId));
    }

    /**
     * How a store view shows prices in its currency, which the widget follows.
     *
     * ICU's pattern for its locale, as Magento's price format reads it.
     *
     * @param int $storeViewId
     * @param string $currency
     * @return array{position: string, thousand_sep: string, decimal_sep: string, decimals: int}|null
     */
    public function currencyFormat(int $storeViewId, string $currency): ?array
    {
        $formatter = new \NumberFormatter(
            $this->locale($storeViewId) . '@currency=' . $currency,
            \NumberFormatter::CURRENCY
        );
        // "¤#,##0.00", "#,##0.00 ¤" (a no-break space), "¤ #,##0.00"…
        $pattern = explode(';', (string) $formatter->getPattern())[0];
        $at = mb_strpos($pattern, '¤');
        if ($at === false) {
            return null;
        }
        $number = (string) preg_replace('/[^0#.,]/', '', $pattern);
        $point = strrpos($number, '.');

        return [
            'position' => ($at === 0 ? 'left' : 'right')
                . (preg_match('/¤[\s\x{00A0}\x{202F}]|[\s\x{00A0}\x{202F}]¤/u', $pattern) ? '_space' : ''),
            'thousand_sep' => (string) $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
            'decimal_sep' => (string) $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            'decimals' => $point === false ? 0 : min(4, strlen($number) - $point - 1),
        ];
    }

    /**
     * The content that can be searched (SourcePool), products first, with how many items a shopper can find.
     *
     * @param Group $store
     * @return list<array{id: string, label: string, kind: string, count: int}>
     */
    public function sources(Group $store): array
    {
        return array_values(array_map(static fn (SourceInterface $source) => [
            'id' => $source->id(),
            'label' => $source->label(),
            'kind' => $source->kind(),
            'count' => $source->count($store),
        ], $this->sources->all()));
    }

    /**
     * The filters the given content can have, each with the sources that have it.
     *
     * Named in a store view's language (the admin's by default), in the
     * sources' order (SourceInterface::facets()).
     *
     * @param string[] $sourceIds
     * @param int $storeViewId
     * @return list<array{attribute: string, label: string, sources: list<string>}>
     */
    public function facets(array $sourceIds, int $storeViewId = 0): array
    {
        $facets = [];
        foreach ($this->sources->all() as $id => $source) {
            if (!in_array($id, $sourceIds, true)) {
                continue;
            }
            foreach ($source->facets($storeViewId) as $attribute => $label) {
                $facets[$attribute] ??= ['attribute' => (string) $attribute, 'label' => $label, 'sources' => []];
                $facets[$attribute]['sources'][] = (string) $id;
            }
        }

        return array_values($facets);
    }

    /**
     * The attributes the layered navigation filters by, which the setup starts with.
     *
     * Those with Use in Layered Navigation.
     *
     * @return list<string>
     */
    public function layeredNavigation(): array
    {
        $codes = [];
        foreach ($this->attributes->create()->addVisibleFilter()->addIsFilterableFilter() as $attribute) {
            $codes[] = (string) $attribute->getAttributeCode();
        }

        return $codes;
    }

    /**
     * The widget's sort choices a source can have, by its kind, and those it
     * starts with. The ids are Bitlane's (server/integration/presets.go),
     * which names them in the widget.
     *
     * @return array<string, array{choices: list<array{id: string, label: string}>, defaults: list<string>}>
     */
    public function sorting(): array
    {
        $both = [
            ['id' => 'relevance', 'label' => (string) __('Relevance')],
            ['id' => 'newest', 'label' => (string) __('Newest')],
            ['id' => 'oldest', 'label' => (string) __('Oldest')],
            ['id' => 'title_asc', 'label' => (string) __('Name: A to Z')],
        ];

        return [
            'products' => [
                'choices' => array_merge($both, [
                    ['id' => 'price_asc', 'label' => (string) __('Price: low to high')],
                    ['id' => 'price_desc', 'label' => (string) __('Price: high to low')],
                    ['id' => 'best_selling', 'label' => (string) __('Best selling')],
                    ['id' => 'top_rated', 'label' => (string) __('Top rated')],
                ]),
                'defaults' => ['relevance', 'price_asc', 'price_desc', 'newest', 'best_selling'],
            ],
            'other' => ['choices' => $both, 'defaults' => ['relevance', 'newest']],
        ];
    }

    /**
     * The store's logo, for the widget.
     *
     * Content › Design › Configuration › Header; empty when the theme's own
     * is used, which has no setting.
     *
     * @param Group $store
     * @return string
     */
    public function logoUrl(Group $store): string
    {
        $view = $this->defaultView($store);
        $logo = (string) $this->config->getValue('design/header/logo_src', ScopeInterface::SCOPE_STORE, $view->getId());

        return $logo !== '' ? $view->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, true) . 'logo/' . $logo : '';
    }

    /**
     * What the setup screen starts with: products and categories, the price,
     * the categories and the layered navigation's attributes, Luma's and
     * Hyvä's search box and the store's logo.
     *
     * @param Group $store
     * @return array<string, mixed> sources, facets, search_selector, brand_color, logo_url
     */
    public function defaultSetup(Group $store): array
    {
        return [
            'sources' => ['product', 'category'],
            'facets' => array_values(array_unique(array_merge(['price', 'category'], array_intersect(
                $this->layeredNavigation(),
                array_column($this->facets(['product']), 'attribute')
            )))),
            'search_selector' => '#search, #search_mini_form button',
            'brand_color' => '',
            'logo_url' => $this->logoUrl($store),
        ];
    }

    /**
     * The store's default store view.
     *
     * @param Group $store
     * @return Store
     */
    private function defaultView(Group $store): Store
    {
        $view = $store->getDefaultStore();
        if (!$view instanceof Store) {
            throw new LocalizedException(__('The store %1 has no store view.', $store->getName()));
        }

        return $view;
    }

    /**
     * A store view's locale ("it_IT").
     *
     * @param int $storeViewId
     * @return string
     */
    private function locale(int $storeViewId): string
    {
        $locale = $this->config->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeViewId);

        return is_string($locale) && $locale !== '' ? $locale : 'en_US';
    }
}
