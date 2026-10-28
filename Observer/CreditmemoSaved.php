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
use Bitlane\Search\Model\Storefront\Events;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;

/**
 * Sales_order_creditmemo_save_after: the refund queued; its products sync, as stock may have come back.
 *
 * MSI's reservations aren't seen by the indexers' triggers (MG11).
 */
class CreditmemoSaved implements ObserverInterface
{
    /**
     * @param Events $events
     * @param ChangesInterface $changes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Events $events,
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
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo instanceof Creditmemo) {
                return;
            }
            $this->events->refunded($creditmemo);
            $products = [];
            foreach ($creditmemo->getAllItems() as $item) {
                $products[] = (int) $item->getProductId();
            }
            $this->changes->add('product', $products);
        } catch (\Throwable $e) {
            // Never in the way of a refund.
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
