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

namespace Bitlane\Search\Cron;

use Bitlane\Search\Model\Sync\DatedPrices as Prices;

/**
 * The cron job bitlane_dated_prices, every hour after Magento's own price refresh (at :00).
 */
class DatedPrices
{
    /**
     * @param Prices $prices
     */
    public function __construct(private readonly Prices $prices)
    {
    }

    /**
     * Queues the products whose dated prices changed today, in each store whose day turned.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->prices->run();
    }
}
