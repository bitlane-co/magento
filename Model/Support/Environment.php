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

use Magento\Framework\App\DeploymentConfig;

/**
 * The install's Bitlane settings in app/etc/env.php, for development:
 * 'bitlane' => ['api_url' => …, 'ca_file' => …] (dev/magento/install.sh
 * writes them).
 */
class Environment
{
    /**
     * @param DeploymentConfig $config
     */
    public function __construct(private readonly DeploymentConfig $config)
    {
    }

    /**
     * The API, https://api.bitlane.co unless bitlane/api_url says otherwise.
     *
     * @return string
     */
    public function apiUrl(): string
    {
        $url = $this->config->get('bitlane/api_url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'https://api.bitlane.co', '/');
    }

    /**
     * The CA bundle for .test hosts; null for the system's.
     *
     * The local stack's certificates come from Herd's CA.
     *
     * @return string|null
     */
    public function caFile(): ?string
    {
        $file = $this->config->get('bitlane/ca_file');

        return is_string($file) && $file !== '' ? $file : null;
    }
}
