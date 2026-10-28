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

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * JSON over HTTP with Magento's cURL client, in all three lines (no
 * libraries, docs/magento-plan.md MG18). A transport failure throws; any
 * HTTP status comes back with its body for the caller to judge.
 */
class Http
{
    /**
     * @param CurlFactory $curlFactory
     * @param Environment $environment
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Environment $environment
    ) {
    }

    /**
     * Sends a request with a JSON or form body.
     *
     * @param string $method
     * @param string $url
     * @param array $options headers, json (a body to encode) or form (fields)
     * @phpstan-param array{headers?: array<string, string>, json?: mixed, form?: array<string, string>} $options
     * @return array{status: int, body: array<string, mixed>, retry_after: int}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        // No Expect: 100-continue, whose interim status the client would take for the answer's.
        $headers = array_merge(['Accept' => 'application/json', 'Expect' => ''], $options['headers'] ?? []);
        $body = '';
        if (array_key_exists('json', $options)) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($options['json']);
        } elseif (isset($options['form'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($options['form'], '', '&', PHP_QUERY_RFC3986);
        }

        $curlOptions = [
            CURLOPT_CONNECTTIMEOUT => 10,
            // The whole list, so an empty value removes cURL's own header.
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value) => $value === '' ? $name . ':' : $name . ': ' . $value,
                array_keys($headers),
                $headers
            ),
        ];
        $caFile = $this->environment->caFile();
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the host of our own URL, not a request's.
        if ($caFile !== null && str_ends_with((string) parse_url($url, PHP_URL_HOST), '.test')) {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
        }
        if ($method !== 'GET' && $method !== 'POST') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        }
        $curl = $this->curlFactory->create();
        $curl->setTimeout(20);
        $curl->setOptions($curlOptions);

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, $body);
            }
        } catch (\Exception $e) {
            throw new BitlaneError('http_error', $e->getMessage() !== '' ? $e->getMessage() : 'The request failed.');
        }

        $retryAfter = 0;
        foreach ($curl->getHeaders() as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                $retryAfter = (int) $value;
            }
        }
        $decoded = json_decode($curl->getBody(), true);

        return [
            'status' => $curl->getStatus(),
            'body' => is_array($decoded) ? $decoded : [],
            'retry_after' => $retryAfter,
        ];
    }
}
