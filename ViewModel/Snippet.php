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

namespace Bitlane\Search\ViewModel;

use Bitlane\Search\Api\StorefrontInterface;
use Bitlane\Search\Model\Storefront\Storefront;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The widget's snippet in the storefront's head (templates/snippet.phtml).
 *
 * Its config, the queue for Bitlane.track() calls made before the loader
 * has run, and with cookie restriction mode the switch to tracking once the
 * shopper allows cookies (at once when they had; Luma's notice's event).
 */
class Snippet implements ArgumentInterface
{
    /**
     * @param StorefrontInterface $shown
     * @param Storefront $storefront
     * @param StoreManagerInterface $storeManager
     * @param HttpRequest $request
     */
    public function __construct(
        private readonly StorefrontInterface $shown,
        private readonly Storefront $storefront,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request
    ) {
    }

    /**
     * The inline script and the loader's address; null when this page shows no widget.
     *
     * @return array{script: string, loader_url: string}|null
     */
    public function snippet(): ?array
    {
        $view = $this->storeManager->getStore();
        if (!$view instanceof Store || !$this->shown->isShown($view, (string) $this->request->getFullActionName())) {
            return null;
        }
        $built = $this->storefront->config($view);
        if ($built === null) {
            return null;
        }
        $json = (string) json_encode(
            $built['config'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        $script = 'window.BitlaneWidgetConfig = ' . $json . ';'
            . 'window.Bitlane = window.Bitlane || { q: [], track: function () { this.q.push(arguments) } };';
        if ($built['consent']) {
            $script .= '(function (c) {'
                . 'function allow() {'
                . ' if (window.Bitlane.consent) { window.Bitlane.consent(true) } else { delete c.tracking } }'
                . 'if (/(^|;\s*)user_allowed_save_cookie=/.test(document.cookie)) { allow() }'
                . ' else if (window.require) { window.require([\'jquery\'], function ($) {'
                . ' $(document).on(\'user:allowed:save:cookie\', allow) }) }'
                . '})(window.BitlaneWidgetConfig);';
        }

        return ['script' => $script, 'loader_url' => $built['loader_url']];
    }
}
