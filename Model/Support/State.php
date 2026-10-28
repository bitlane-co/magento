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

use Magento\Framework\App\ResourceConnection;

/**
 * What the extension keeps, in its own table bitlane_state: a value by name,
 * for the whole install (store 0) or one store group (docs/magento-plan.md
 * MG18). Unlike Magento's configuration, which is cached, every read goes to
 * the database, since another request may have refreshed the tokens meanwhile.
 */
class State
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * A value; null when there's none.
     *
     * @param string $name
     * @param int $storeId
     * @return string|null
     */
    public function get(string $name, int $storeId = 0): ?string
    {
        $db = $this->resource->getConnection();
        $value = $db->fetchOne(
            $db->select()->from($this->table(), 'value')->where('store_id = ?', $storeId)->where('name = ?', $name)
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Stores a value; null removes it.
     *
     * @param string $name
     * @param string|null $value
     * @param int $storeId
     * @return void
     */
    public function set(string $name, ?string $value, int $storeId = 0): void
    {
        $db = $this->resource->getConnection();
        if ($value === null) {
            $db->delete($this->table(), ['store_id = ?' => $storeId, 'name = ?' => $name]);

            return;
        }
        $db->insertOnDuplicate(
            $this->table(),
            ['store_id' => $storeId, 'name' => $name, 'value' => $value, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['value', 'updated_at']
        );
    }

    /**
     * A JSON value, an empty array when there's none.
     *
     * @param string $name
     * @param int $storeId
     * @return array<string, mixed>
     */
    public function json(string $name, int $storeId = 0): array
    {
        $data = json_decode((string) $this->get($name, $storeId), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Stores a JSON value; null removes it.
     *
     * @param string $name
     * @param array<string,mixed>|null $value
     * @param int $storeId
     * @return void
     */
    public function setJson(string $name, ?array $value, int $storeId = 0): void
    {
        $this->set($name, $value === null ? null : (string) json_encode($value), $storeId);
    }

    /**
     * Removes everything kept for a store group, or for every one with 0.
     *
     * The install's own rows stay.
     *
     * @param int $storeId
     * @return void
     */
    public function forgetStore(int $storeId = 0): void
    {
        $this->resource->getConnection()->delete(
            $this->table(),
            $storeId > 0 ? ['store_id = ?' => $storeId] : ['store_id > ?' => 0]
        );
    }

    /**
     * Removes the install's names that start with $prefix, last written before $before.
     *
     * $before is in Unix seconds.
     *
     * @param string $prefix
     * @param int $before
     * @return void
     */
    public function prune(string $prefix, int $before): void
    {
        $this->resource->getConnection()->delete($this->table(), [
            'store_id = ?' => 0,
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- escapes LIKE's wildcards in the prefix.
            'name LIKE ?' => addcslashes($prefix, '%_') . '%',
            'updated_at < ?' => gmdate('Y-m-d H:i:s', $before),
        ]);
    }

    /**
     * The table's name, with the install's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName('bitlane_state');
    }
}
