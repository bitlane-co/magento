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
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Sales_order_invoice_pay: an order paid (online capture, offline payment invoiced), its purchase queued once.
 */
class InvoicePaid implements ObserverInterface
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
            $invoice = $observer->getEvent()->getData('invoice');
            if ($invoice instanceof Invoice) {
                $this->events->invoicePaid($invoice->getOrder());
            }
        } catch (\Throwable $e) {
            // Never in the way of a payment.
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
