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

namespace Bitlane\Search\Model\Support;

use Magento\Framework\Module\PackageInfo;

/**
 * A newer release of the extension on Packagist, where it's installed from (docs/magento-plan.md MG19).
 *
 * Read at most daily from Packagist's metadata of bitlane/magento, kept in
 * bitlane_state, for the Bitlane screen's notice (composer outdated tells
 * too).
 */
class Updates
{
    /**
     * @param State $state
     * @param Http $http
     * @param PackageInfo $packageInfo
     */
    public function __construct(
        private readonly State $state,
        private readonly Http $http,
        private readonly PackageInfo $packageInfo
    ) {
    }

    /**
     * The newer release's version; null when this one is the latest, or unknown.
     *
     * A copy without a release's version in its composer.json (the
     * repository's own) is never told.
     *
     * @return array{version: string}|null
     */
    public function available(): ?array
    {
        $installed = (string) $this->packageInfo->getVersion('Bitlane_Search');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $installed)) {
            return null;
        }
        $kept = $this->state->json('update');
        if ((int) ($kept['at'] ?? 0) < time() - 86400) {
            $kept = ['at' => time(), 'version' => $this->latest()];
            $this->state->setJson('update', $kept);
        }
        $version = (string) ($kept['version'] ?? '');

        return $version !== '' && version_compare($version, $installed, '>') ? ['version' => $version] : null;
    }

    /**
     * The newest stable version Packagist lists; empty when it can't be read.
     *
     * @return string
     */
    private function latest(): string
    {
        try {
            $res = $this->http->request('GET', 'https://repo.packagist.org/p2/bitlane/magento.json');
        } catch (BitlaneError) {
            return '';
        }
        $latest = '';
        foreach ((array) ($res['body']['packages']['bitlane/magento'] ?? []) as $release) {
            $version = ltrim((string) ($release['version'] ?? ''), 'v');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version) && version_compare($version, $latest ?: '0', '>')) {
                $latest = $version;
            }
        }

        return $res['status'] === 200 ? $latest : '';
    }
}
