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

namespace Bitlane\Search\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The Bitlane screen (docs/magento-plan.md MG14): the mount point of its
 * React app (admin/, built into view/adminhtml/web), which talks to
 * Controller\Adminhtml\Action\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Bitlane_Search::view';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    /**
     * The page, its layout bitlane_index_index.
     *
     * @return Page
     */
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        if ($page instanceof BackendPage) {
            $page->setActiveMenu('Bitlane_Search::bitlane');
        }
        $page->getConfig()->getTitle()->prepend((string) __('Bitlane'));

        return $page;
    }
}
