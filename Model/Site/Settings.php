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

use Bitlane\Search\Model\Support\State;
use Magento\Store\Model\Group;

/**
 * A store's choices on the setup screen, kept to show them again and for the
 * sync: the engine, the content and filters, the theme's search box, the
 * brand, and product_documents, how a configurable product becomes search
 * documents (docs/magento-plan.md MG6).
 */
class Settings
{
    /**
     * One document per configurable product, its children folded in: the
     * fewest documents, but filters combine values of different children.
     */
    public const PER_PRODUCT = 'per_product';

    /**
     * One document per child: colour, size and stock filter exactly, with
     * more documents (counted in the plan's records). The default.
     */
    public const PER_VARIATION = 'per_variation';

    public const PRODUCT_DOCUMENTS = [self::PER_PRODUCT, self::PER_VARIATION];

    /**
     * @param State $state
     * @param Site $site
     */
    public function __construct(
        private readonly State $state,
        private readonly Site $site
    ) {
    }

    /**
     * The store's settings, the defaults for what isn't set.
     *
     * @param Group $store
     * @return array<string, mixed>
     */
    public function get(Group $store): array
    {
        return array_merge(
            [
                'engine_id' => null,
                // Sources synced for the store's own use, which widgets don't search.
                'outside_widget' => [],
                // Each source's sort choices, in order (Site::sorting()); a
                // source without an entry has its kind's defaults.
                'sorting' => [],
                'product_documents' => self::PER_VARIATION,
                // The languages Bitlane was last sent.
                'languages' => [],
            ],
            $this->site->defaultSetup($store),
            $this->state->json('settings', (int) $store->getId())
        );
    }

    /**
     * What a source's documents depend on, as a short hash: a new one means a full sync of the source.
     *
     * Its picked filters, the languages (a new one has an empty index), and
     * for products how they become documents.
     *
     * @param Group $store
     * @param string $source
     * @return string
     */
    public function hash(Group $store, string $source): string
    {
        $settings = $this->get($store);
        $facets = array_values(array_intersect(
            (array) $settings['facets'],
            array_column($this->site->facets([$source]), 'attribute')
        ));

        return hash('sha256', (string) json_encode([
            $source,
            $facets,
            $settings['languages'],
            $source === 'product' ? $settings['product_documents'] : null,
        ]));
    }

    /**
     * Whether the store's setup was sent to Bitlane.
     *
     * @param Group $store
     * @return bool
     */
    public function saved(Group $store): bool
    {
        return $this->state->get('settings', (int) $store->getId()) !== null;
    }

    /**
     * Keeps the store's choices.
     *
     * @param Group $store
     * @param array<string,mixed> $settings
     * @return void
     */
    public function save(Group $store, array $settings): void
    {
        $this->state->setJson('settings', $settings, (int) $store->getId());
    }
}
