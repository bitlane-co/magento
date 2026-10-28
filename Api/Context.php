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

namespace Bitlane\Search\Api;

use Magento\Store\Model\Group;
use Magento\Store\Model\Store;

/**
 * What documents are built for: a store, one of its store views (its language and currency), and the setup's choices.
 */
class Context
{
    /**
     * @param Group $store
     * @param Store $storeView
     * @param string $language the store view's code in Bitlane ("it-IT")
     * @param string $currency the store view's display currency
     * @param list<string> $facets the filters the merchant picked that this source has
     * @param string $productDocuments per_variation or per_product
     */
    public function __construct(
        public readonly Group $store,
        public readonly Store $storeView,
        public readonly string $language,
        public readonly string $currency,
        public readonly array $facets,
        public readonly string $productDocuments
    ) {
    }
}
