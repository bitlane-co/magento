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

/**
 * Changed items of a source, which sync in the background in every store that syncs it.
 *
 * The built-in sources learn of their changes from the extension's indexers;
 * a module's source reports its own with add().
 */
interface ChangesInterface
{
    /**
     * Queues the items to sync, in every store that has the source.
     *
     * @param string $source the source's id
     * @param array<int|string> $ids its items
     * @return void
     */
    public function add(string $source, array $ids): void;
}
