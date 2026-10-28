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

use Magento\Store\Model\Store;

/**
 * The search widget on the storefront's pages.
 *
 * A module leaves pages out with an after plugin on isShown() in its di.xml.
 *
 * @api
 */
interface StorefrontInterface
{
    /**
     * Whether a page of a store view shows the widget: the store's on and has an active widget for the view.
     *
     * Checkout pages never do (their stricter security policy, and no search
     * there). The page is Magento's full action name ("catalog_product_view").
     *
     * @param Store $view
     * @param string $page
     * @return bool
     */
    public function isShown(Store $view, string $page): bool;
}
