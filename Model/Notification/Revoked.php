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

namespace Bitlane\Search\Model\Notification;

use Bitlane\Search\Model\Connection\ConnectionStore;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;

/**
 * The admin's system message once Bitlane ended the connection (the app
 * revoked there, a password reset): the stores' search stopped updating, and
 * Connect again is on the Bitlane screen.
 */
class Revoked implements MessageInterface
{
    /**
     * @param ConnectionStore $connection
     * @param AuthorizationInterface $authorization
     * @param UrlInterface $url
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly ConnectionStore $connection,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        return 'bitlane_connection_revoked';
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->authorization->isAllowed('Bitlane_Search::view') && !empty($this->connection->get()['revoked']);
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        return $this->escaper->escapeHtml(
            (string) __('Bitlane ended this store’s connection, so its search stopped updating.')
        ) . ' <a href="' . $this->escaper->escapeUrl($this->url->getUrl('bitlane/index/index')) . '">'
            . $this->escaper->escapeHtml((string) __('Connect again')) . '</a>';
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
