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

use Bitlane\Search\Model\Screen\Texts;
use Bitlane\Search\Model\Support\State;
use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Module\PackageInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * What the Bitlane screen's app starts with (admin/src/types.ts, Config), on
 * its mount point's data-config: no inline script, which the admin's CSP
 * would have to allow.
 */
class Screen implements ArgumentInterface
{
    /**
     * @param UrlInterface $url
     * @param FormKey $formKey
     * @param Session $session
     * @param State $state
     * @param Texts $texts
     * @param PackageInfo $packageInfo
     * @param Json $json
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly Session $session,
        private readonly State $state,
        private readonly Texts $texts,
        private readonly PackageInfo $packageInfo,
        private readonly Json $json
    ) {
    }

    /**
     * The app's config as JSON; the callback's outcome goes with it once.
     *
     * @return string
     */
    public function config(): string
    {
        $key = 'notice:' . (int) $this->session->getUser()?->getId();
        $notice = $this->state->json($key);
        $this->state->set($key, null);

        return (string) $this->json->serialize([
            // The actions' URL with the admin's secret key; a change also sends form_key.
            'actions' => $this->url->getUrl('bitlane/action/index'),
            'form_key' => $this->formKey->getFormKey(),
            'page' => $this->url->getUrl('bitlane/index/index'),
            'notice' => $notice ?: null,
            'version' => $this->packageInfo->getVersion('Bitlane_Search'),
            'translations' => $this->texts->all(),
        ]);
    }
}
