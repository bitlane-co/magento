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
 * One request at a time through a piece of work, with MySQL's named locks
 * (GET_LOCK), which go away with the connection if PHP dies holding them.
 * Not Magento's LockManagerInterface: its database backend allows one lock
 * per connection, and the queue's runner refreshes tokens while holding its
 * own.
 */
class Lock
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Runs $work holding the lock, waiting up to $wait seconds for it.
     *
     * Without the lock it runs anyway: the server's grace window covers a
     * second refresh.
     *
     * @template T
     * @param string $name
     * @param callable $work
     * @phpstan-param callable(): T $work
     * @param int $wait
     * @return T
     */
    public function with(string $name, callable $work, int $wait = 10): mixed
    {
        $db = $this->resource->getConnection();
        $key = $this->key($name);
        $held = (int) $db->fetchOne('SELECT GET_LOCK(?, ?)', [$key, $wait]) === 1;
        try {
            return $work();
        } finally {
            if ($held) {
                $db->fetchOne('SELECT RELEASE_LOCK(?)', [$key]);
            }
        }
    }

    /**
     * Runs $work only if the lock is free now; false when another holds it.
     *
     * @param string $name
     * @param callable $work
     * @phpstan-param callable(): void $work
     * @return bool
     */
    public function attempt(string $name, callable $work): bool
    {
        $db = $this->resource->getConnection();
        $key = $this->key($name);
        if ((int) $db->fetchOne('SELECT GET_LOCK(?, 0)', [$key]) !== 1) {
            return false;
        }
        try {
            $work();
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$key]);
        }

        return true;
    }

    /**
     * The lock's name on the MySQL server, at most 64 characters.
     *
     * Other databases on the server share the names, so the install's is in it.
     *
     * @param string $name
     * @return string
     */
    private function key(string $name): string
    {
        $database = $this->resource->getSchemaName(ResourceConnection::DEFAULT_CONNECTION);

        return 'bitlane_' . substr(hash('sha256', $database . '|' . $name), 0, 40);
    }
}
