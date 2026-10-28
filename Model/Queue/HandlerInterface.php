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

namespace Bitlane\Search\Model\Queue;

use Bitlane\Search\Model\Support\BitlaneError;

/**
 * What runs a type of job, listed in Runner's handlers (etc/di.xml) by type.
 */
interface HandlerInterface
{
    /**
     * Does the job; a BitlaneError is retried with backoff (Retry), anything else fails it.
     *
     * @param int $storeId the store group
     * @param array<mixed> $args the job's arguments
     * @return void
     * @throws BitlaneError
     */
    public function run(int $storeId, array $args): void;
}
