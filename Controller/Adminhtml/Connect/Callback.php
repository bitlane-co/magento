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

namespace Bitlane\Search\Controller\Adminhtml\Connect;

use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Connection\OAuthClient;
use Bitlane\Search\Model\Screen\Actions;
use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\State;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Bitlane's answer to Connect (docs/magento-plan.md MG2): exchanges the
 * code, connects the store when the grant names its project, and goes back
 * to the screen with the outcome as a notice. Bitlane's redirect can't carry
 * the admin URL's secret key (a client's redirect URI is fixed), so this is
 * a public action: it still needs the admin signed in with
 * Bitlane_Search::manage, and the OAuth state, made for that admin user, is
 * its check.
 */
class Callback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Bitlane_Search::manage';

    /**
     * @var string[]
     */
    protected $_publicActions = ['callback'];

    /**
     * @param Context $context
     * @param OAuthClient $oauth
     * @param ConnectionStore $connection
     * @param Actions $actions
     * @param State $state
     * @param Session $session
     */
    public function __construct(
        Context $context,
        private readonly OAuthClient $oauth,
        private readonly ConnectionStore $connection,
        private readonly Actions $actions,
        private readonly State $state,
        private readonly Session $session
    ) {
        parent::__construct($context);
    }

    /**
     * Back to the screen, for the store Connect was clicked for.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $query = [];
        foreach (['code', 'state', 'iss', 'error', 'error_description'] as $key) {
            $value = $this->getRequest()->getParam($key);
            if (is_string($value) && $value !== '') {
                $query[$key] = $value;
            }
        }
        $adminUserId = (int) $this->session->getUser()?->getId();
        $storeId = 0;
        try {
            $storeId = $this->oauth->handleCallback($query, $adminUserId);
            $store = $this->actions->store($storeId);
            if ($store && !empty($this->connection->get()['project_id'])) {
                $this->actions->connectStore($store);
            }
            $notice = ['type' => 'success', 'message' => (string) __('Connected to Bitlane.')];
        } catch (BitlaneError $e) {
            $notice = ['type' => 'error', 'message' => $e->getMessage()];
        }
        $this->state->setJson('notice:' . $adminUserId, $notice);

        return $this->resultRedirectFactory->create()->setPath(
            'bitlane/index/index',
            $storeId ? ['_query' => ['bitlane_store' => $storeId]] : []
        );
    }
}
