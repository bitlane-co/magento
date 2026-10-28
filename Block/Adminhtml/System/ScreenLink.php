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

namespace Bitlane\Search\Block\Adminhtml\System;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Stores › Configuration › Services › Bitlane: a link to the Bitlane
 * screen, where everything is set (docs/magento-plan.md MG14), not settings
 * of its own.
 */
class ScreenLink extends Field
{
    /**
     * The link, as the field's whole row.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return '<tr><td colspan="4"><p>'
            . $this->escapeHtml((string) __('Bitlane is connected and set up on its own screen, for each store.'))
            . ' <a href="' . $this->escapeUrl($this->getUrl('bitlane/index/index')) . '">'
            . $this->escapeHtml((string) __('Open Bitlane')) . '</a></p></td></tr>';
    }
}
