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

use Bitlane\Search\Model\Storefront\Events;

/**
 * The cron job bitlane_prune, daily: sessions of quotes untouched for 90 days go.
 */
class Prune
{
    /**
     * @param Events $events
     */
    public function __construct(private readonly Events $events)
    {
    }

    /**
     * Deletes the old quotes' sessions.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->events->prune();
    }
}
