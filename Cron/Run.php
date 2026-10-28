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

use Bitlane\Search\Model\Queue\Runner;

/**
 * The cron job bitlane_run, every minute in the cron group bitlane (its own process).
 *
 * Runs the due jobs for most of the minute, so the next run takes over.
 */
class Run
{
    /**
     * @param Runner $runner
     */
    public function __construct(private readonly Runner $runner)
    {
    }

    /**
     * Runs due jobs for up to 50 seconds.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->runner->run(50);
    }
}
