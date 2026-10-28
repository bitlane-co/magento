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

use Bitlane\Search\Api\ChangesInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Sales_model_service_quote_submit_success: an order's products sync, as their stock went down.
 *
 * MSI reserves stock without writing the tables the indexers' triggers
 * follow (MG11), so a product sold out by the order would stay "in stock".
 */
class OrderPlaced implements ObserverInterface
{
    /**
     * @param ChangesInterface $changes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ChangesInterface $changes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }
            $products = [];
            foreach ($order->getAllItems() as $item) {
                $products[] = (int) $item->getProductId();
            }
            $this->changes->add('product', $products);
        } catch (\Throwable $e) {
            // Never in the way of an order.
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
