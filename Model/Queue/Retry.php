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
 * When to try again what can pass later, as the plugins do.
 *
 * No connection, 429, 401/403 (a replaced write key, read again), 5xx, and
 * 503 with Retry-After while an engine upgrades are retried with backoff;
 * anything else, and a store no longer connected, gives up.
 */
class Retry
{
    /**
     * Seconds until the next attempt, or null to give up (after 8 attempts).
     *
     * @param BitlaneError $error
     * @param int $attempt attempts so far, 0 after the first
     * @return int|null
     */
    public function after(BitlaneError $error, int $attempt): ?int
    {
        if ($attempt >= 8 || in_array($error->kind, ['disconnected', 'not_connected'], true)) {
            return null;
        }
        $status = (int) $error->status;
        if ($status !== 0 && !in_array($status, [401, 403, 429], true) && $status < 500) {
            return null;
        }

        return $error->retryAfter > 0 ? $error->retryAfter : min(3600, 30 * (2 ** $attempt));
    }
}
