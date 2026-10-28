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
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Sales_quote_save_after: the widget's session onto the quote, for the order placed from it.
 */
class QuoteSaved implements ObserverInterface
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
            $quote = $observer->getEvent()->getData('quote');
            if ($quote instanceof Quote) {
                $this->events->quoteSaved($quote);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
