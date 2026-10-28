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

namespace Bitlane\Search\Observer;

use Bitlane\Search\Model\Storefront\Events;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Sales_quote_product_add_after: the additions to the cart, for Bitlane's analytics.
 */
class QuoteProductAdded implements ObserverInterface
{
    /**
     * @param Events $events
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Events $events,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        try {
            $this->events->added(array_values((array) $observer->getEvent()->getData('items')));
        } catch (\Throwable $e) {
            // Never in the way of a cart.
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
