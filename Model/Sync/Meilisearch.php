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

use Bitlane\Search\Model\Support\BitlaneError;
use Bitlane\Search\Model\Support\Http;

/**
 * The few Meilisearch calls the sync makes, with the write key from Credentials; no SDK.
 *
 * Writes are Meilisearch tasks: queued there, not waited for. An error
 * carries the status and Retry-After, which Retry reads. An index is
 * ['url' => engine, 'key' => write key, 'index' => uid].
 */
class Meilisearch
{
    /**
     * @param Http $http
     */
    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Adds or replaces documents, in requests of at most 8 MB; a bigger document goes on its own.
     *
     * The first failure stops it; a retry sends them all again.
     *
     * @param array<string,string> $index
     * @param list<array<string,mixed>> $documents
     * @return void
     */
    public function add(array $index, array $documents): void
    {
        $chunk = [];
        $size = 0;
        foreach ($documents as $document) {
            // Its JSON and the comma between documents.
            $bytes = strlen((string) json_encode($document)) + 1;
            if ($chunk && $size + $bytes > 8000000) {
                $this->send($index, 'POST', '/documents?primaryKey=id', $chunk);
                $chunk = [];
                $size = 0;
            }
            $chunk[] = $document;
            $size += $bytes;
        }
        if ($chunk) {
            $this->send($index, 'POST', '/documents?primaryKey=id', $chunk);
        }
    }

    /**
     * Deletes documents by id.
     *
     * @param array<string,string> $index
     * @param list<string> $ids
     * @return void
     */
    public function delete(array $index, array $ids): void
    {
        if ($ids) {
            $this->send($index, 'POST', '/documents/delete-batch', $ids);
        }
    }

    /**
     * Deletes the documents a filter matches.
     *
     * @param array<string,string> $index
     * @param string $filter
     * @return void
     */
    public function deleteWhere(array $index, string $filter): void
    {
        $this->send($index, 'POST', '/documents/delete', ['filter' => $filter]);
    }

    /**
     * The ids of the documents a filter matches, up to 10,000 (a batch of products' variants).
     *
     * @param array<string,string> $index
     * @param string $filter
     * @return list<string>
     */
    public function documentIds(array $index, string $filter): array
    {
        $res = $this->send($index, 'POST', '/documents/fetch', [
            'filter' => $filter,
            'fields' => ['id'],
            'limit' => 10000,
        ]);

        return array_values(array_map(
            static fn ($document) => (string) $document['id'],
            (array) ($res['results'] ?? [])
        ));
    }

    /**
     * Which of the items the index has, when their documents say each changed, and how many documents those are.
     *
     * Products by parent_id (several documents an item), other content by id;
     * modified_at is 0 without one, a product's latest. Only those fields
     * come back, and it isn't a search, so it isn't billed.
     *
     * @param array<string,string> $index
     * @param bool $products
     * @param list<string> $ids
     * @return array{items: list<string>, modified: array<string, int>, documents: int}
     */
    public function present(array $index, bool $products, array $ids): array
    {
        $field = $products ? 'parent_id' : 'id';
        $quoted = array_map(static fn ($id) => (string) json_encode($id), $ids);
        $body = $products ? ['filter' => 'parent_id IN [' . implode(', ', $quoted) . ']'] : ['ids' => $ids];
        $modified = [];
        for ($offset = 0;; $offset += 10000) {
            $res = $this->send($index, 'POST', '/documents/fetch', $body + [
                'fields' => [$field, 'modified_at'],
                'limit' => 10000,
                'offset' => $offset,
            ]);
            foreach ((array) ($res['results'] ?? []) as $document) {
                $item = (string) $document[$field];
                $modified[$item] = max($modified[$item] ?? 0, (int) ($document['modified_at'] ?? 0));
            }
            $documents = (int) ($res['total'] ?? 0);
            if ($offset + 10000 >= $documents) {
                return [
                    'items' => array_map('strval', array_keys($modified)),
                    'modified' => $modified,
                    'documents' => $documents,
                ];
            }
        }
    }

    /**
     * How many documents the index has (the write key has documents.*, not stats.get).
     *
     * @param array<string,string> $index
     * @return int
     */
    public function count(array $index): int
    {
        return (int) ($this->send($index, 'GET', '/documents?limit=0&fields=id')['total'] ?? 0);
    }

    /**
     * One call to the index; an error status throws.
     *
     * @param array<string,string> $index
     * @param string $method
     * @param string $path
     * @param array<mixed>|null $body
     * @return array<string,mixed>
     */
    private function send(array $index, string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $index['key']]];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $url = $index['url'] . '/indexes/' . rawurlencode($index['index']) . $path;
        $res = $this->http->request($method, $url, $options);
        if ($res['status'] >= 400) {
            throw new BitlaneError(
                (string) ($res['body']['code'] ?? 'meilisearch'),
                (string) ($res['body']['message'] ?? 'Meilisearch answered ' . $res['status']),
                $res['status'],
                null,
                $res['retry_after']
            );
        }

        return $res['body'];
    }
}
