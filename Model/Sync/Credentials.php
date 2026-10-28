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

namespace Bitlane\Search\Model\Sync;

use Bitlane\Search\Model\Connection\BitlaneApi;
use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\State;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Where a store's documents go: each engine's address and a write key limited to the store's indexes.
 *
 * Bitlane's GET /api/integrations/:id/credentials (a key lasts a week,
 * renewed with two days left), kept encrypted for an hour per store.
 */
class Credentials
{
    /**
     * @param BitlaneApi $api
     * @param ConnectionStore $connection
     * @param State $state
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly BitlaneApi $api,
        private readonly ConnectionStore $connection,
        private readonly State $state,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Where a source's documents in a language go: its own index.
     *
     * An index the kept credentials don't have yet (a new setup made it) is
     * looked for again in fresh ones.
     *
     * @param int $storeId
     * @param string $source
     * @param string $language as the setup sent it ("en-US")
     * @return array<string,string> url, key, index
     */
    public function index(int $storeId, string $source, string $language): array
    {
        foreach ([false, true] as $fresh) {
            if ($fresh) {
                $this->forget($storeId);
            }
            foreach ($this->get($storeId)['engines'] as $engine) {
                foreach ((array) ($engine['indexes'] ?? []) as $index) {
                    if (($index['source'] ?? '') === $source
                        && strtolower((string) ($index['language'] ?? '')) === strtolower($language)
                    ) {
                        return [
                            'url' => rtrim((string) $engine['engine_url'], '/'),
                            'key' => (string) $engine['write_key'],
                            'index' => (string) $index['uid'],
                        ];
                    }
                }
            }
        }
        throw new BitlaneError(
            'no_index',
            sprintf('Bitlane has no index for %s in %s for this store yet.', $source, $language),
            404
        );
    }

    /**
     * The credentials, kept or from Bitlane.
     *
     * @param int $storeId
     * @return array{index_prefix: string, engines: list<array<string, mixed>>}
     */
    public function get(int $storeId): array
    {
        $kept = $this->state->get('credentials', $storeId);
        $data = $kept === null ? null : json_decode($this->encryptor->decrypt($kept), true);
        if (is_array($data) && ($data['at'] ?? 0) > time() - 3600) {
            return [
                'index_prefix' => (string) $data['index_prefix'],
                'engines' => array_values((array) $data['engines']),
            ];
        }

        $id = $this->connection->integrationId($storeId);
        if (!$id) {
            throw new BitlaneError('not_connected', (string) __('This store isn’t connected to Bitlane.'), 409);
        }
        $answer = $this->api->request('GET', '/api/integrations/' . rawurlencode($id) . '/credentials');
        $data = [
            'index_prefix' => (string) ($answer['index_prefix'] ?? ''),
            'engines' => array_values((array) ($answer['engines'] ?? [])),
        ];
        $encrypted = $this->encryptor->encrypt((string) json_encode($data + ['at' => time()]));
        $this->state->set('credentials', $encrypted, $storeId);

        return $data;
    }

    /**
     * Drops the kept credentials: a refused key, a new setup, a disconnect.
     *
     * @param int $storeId
     * @return void
     */
    public function forget(int $storeId): void
    {
        $this->state->set('credentials', null, $storeId);
    }
}
