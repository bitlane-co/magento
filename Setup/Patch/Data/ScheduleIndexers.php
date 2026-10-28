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

namespace Bitlane\Search\Setup\Patch\Data;

use Bitlane\Search\Model\Sync\IndexerModes;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * The sync's indexers on "Update by Schedule" from the install on (docs/magento-plan.md MG20).
 *
 * Outside a transaction: scheduling creates triggers, which MySQL refuses inside one (2.4.7 checks).
 */
class ScheduleIndexers implements DataPatchInterface, NonTransactionableInterface
{
    /**
     * @param IndexerModes $indexerModes
     */
    public function __construct(private readonly IndexerModes $indexerModes)
    {
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->indexerModes->schedule();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
