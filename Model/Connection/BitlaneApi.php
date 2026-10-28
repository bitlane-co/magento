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

namespace Bitlane\Search\Model\Connection;

use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\Http;
use Magento\Framework\Locale\ResolverInterface;

/**
 * Bitlane's API with the grant's access token. A 401 is retried once with
 * refreshed tokens; a second one means the grant ended (the app was revoked
 * in Bitlane), and the connection is closed.
 */
class BitlaneApi
{
    /**
     * @param OAuthClient $oauth
     * @param ConnectionStore $connection
     * @param Http $http
     * @param ResolverInterface $locale
     */
    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly ConnectionStore $connection,
        private readonly Http $http,
        private readonly ResolverInterface $locale
    ) {
    }

    /**
     * Sends a request and returns the decoded answer.
     *
     * An error status throws with Bitlane's message and field errors.
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|null $body a JSON body
     * @param array<string,scalar> $query
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $token = $this->oauth->accessToken();
        $res = $this->send($method, $path, $body, $query, $token);
        if ($res['status'] === 401) {
            $token = $this->oauth->refresh($token);
            $res = $this->send($method, $path, $body, $query, $token);
            if ($res['status'] === 401) {
                $this->connection->end(true);
                throw new BitlaneError(
                    'disconnected',
                    (string) __('Bitlane ended this store’s connection. Connect again to keep searching.'),
                    401
                );
            }
        }
        if ($res['status'] >= 400) {
            throw new BitlaneError(
                'api',
                (string) ($res['body']['message'] ?? __('Bitlane could not do that. Try again in a moment.')),
                $res['status'],
                is_array($res['body']['errors'] ?? null) ? $res['body']['errors'] : null
            );
        }

        return $res['body'];
    }

    /**
     * One request with the given token.
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @param string $token
     * @return array{status: int, body: array<string, mixed>, retry_after: int}
     */
    private function send(string $method, string $path, ?array $body, array $query, string $token): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                // Bitlane answers in the admin's language (en or it): it_IT is it.
                'Accept-Language' => strtok($this->locale->getLocale(), '_') ?: 'en',
            ],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $url = $this->oauth->apiUrl() . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->http->request($method, $url, $options);
    }
}
