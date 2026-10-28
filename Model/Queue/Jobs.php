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

namespace Bitlane\Search\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The extension's background work, in its table bitlane_job (docs/magento-plan.md MG10).
 *
 * Not Magento's message queue: a failed message isn't retried, nor can one
 * wait until a given time, and the sync needs both (backoff, Retry-After,
 * prices that change tomorrow). A job has a store group, a type (what runs
 * it, Runner), arguments in JSON, when it's due and how many attempts it had.
 * A job is claimed by writing a token and a lock time with one UPDATE (MySQL
 * and MariaDB alike), so two runners never take the same one, and a runner
 * that died lets go when its lock time passes.
 *
 * The table holds work to do, not a history: a job that finishes is deleted
 * at once, one that gives up stays a week with its error (failed_at) for the
 * Jobs tab, then prune() deletes it. Times are UTC.
 */
class Jobs
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Queues a job, due now or after $delay seconds.
     *
     * With a $key, a job of the store with that key still waiting is kept
     * instead (the daily check, a special price's start).
     *
     * @param int $storeId
     * @param string $type
     * @param array<mixed> $args
     * @param int $delay
     * @param string|null $key
     * @return void
     */
    public function push(int $storeId, string $type, array $args = [], int $delay = 0, ?string $key = null): void
    {
        if ($key !== null && $this->has($storeId, $key)) {
            return;
        }
        $this->db()->insert($this->table(), [
            'store_id' => $storeId,
            'type' => $type,
            'args' => (string) json_encode($args),
            'job_key' => $key,
            'run_at' => $this->time($delay),
            'attempts' => 0,
            'created_at' => $this->time(),
        ]);
    }

    /**
     * Whether a job of the store with that key waits (or runs).
     *
     * @param int $storeId
     * @param string $key
     * @return bool
     */
    public function has(int $storeId, string $key): bool
    {
        $db = $this->db();

        return (bool) $db->fetchOne(
            $db->select()->from($this->table(), 'job_id')
                ->where('store_id = ?', $storeId)->where('job_key = ?', $key)->where('failed_at IS NULL')->limit(1)
        );
    }

    /**
     * Claims the next due job for $lock seconds; null when none is due.
     *
     * @param int $lock
     * @return array{id: int, store: int, type: string, args: array<mixed>, attempts: int}|null
     */
    public function claim(int $lock = 300): ?array
    {
        $db = $this->db();
        $token = bin2hex(random_bytes(16));
        $db->query(
            'UPDATE ' . $db->quoteIdentifier($this->table())
            . ' SET lock_token = ?, locked_until = ? WHERE ' . $this->due() . ' ORDER BY run_at, job_id LIMIT 1',
            [$token, $this->time($lock)]
        );
        $row = $db->fetchRow($db->select()->from($this->table())->where('lock_token = ?', $token));
        if (!is_array($row)) {
            return null;
        }
        $args = json_decode((string) $row['args'], true);

        return [
            'id' => (int) $row['job_id'],
            'store' => (int) $row['store_id'],
            'type' => (string) $row['type'],
            'args' => is_array($args) ? $args : [],
            'attempts' => (int) $row['attempts'],
        ];
    }

    /**
     * A job finished: it goes.
     *
     * @param int $id
     * @return void
     */
    public function done(int $id): void
    {
        $this->db()->delete($this->table(), ['job_id = ?' => $id]);
    }

    /**
     * Runs a job again after $delay seconds, one attempt more, noting why.
     *
     * @param int $id
     * @param int $delay
     * @param string $error
     * @return void
     */
    public function retry(int $id, int $delay, string $error): void
    {
        $this->db()->update($this->table(), [
            'run_at' => $this->time($delay),
            'attempts' => new \Zend_Db_Expr('attempts + 1'),
            'lock_token' => null,
            'locked_until' => null,
            'last_error' => mb_substr($error, 0, 1000),
        ], ['job_id = ?' => $id]);
    }

    /**
     * A job gave up: it stays, with its error, until prune().
     *
     * @param int $id
     * @param string $error
     * @return void
     */
    public function fail(int $id, string $error): void
    {
        $this->db()->update($this->table(), [
            'failed_at' => $this->time(),
            'attempts' => new \Zend_Db_Expr('attempts + 1'),
            'lock_token' => null,
            'locked_until' => null,
            'last_error' => mb_substr($error, 0, 1000),
        ], ['job_id = ?' => $id]);
    }

    /**
     * Deletes the jobs that failed more than a week ago.
     *
     * @return void
     */
    public function prune(): void
    {
        $this->db()->delete($this->table(), ['failed_at < ?' => $this->time(-7 * 86400)]);
    }

    /**
     * The store's jobs for the Jobs tab: running first, then due, then later ones, then failed.
     *
     * @param int $storeId
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public function list(int $storeId, int $limit = 100): array
    {
        $db = $this->db();
        $now = $this->time();
        $rows = $db->fetchAll(
            $db->select()->from($this->table())->where('store_id = ?', $storeId)
                ->order(new \Zend_Db_Expr('failed_at IS NOT NULL'))
                ->order(new \Zend_Db_Expr($db->quoteInto('locked_until > ?', $now) . ' DESC'))
                ->order(['run_at', 'job_id'])
                ->limit($limit)
        );

        return array_map(function (array $row) use ($now): array {
            $args = json_decode((string) $row['args'], true);

            return [
                'id' => (int) $row['job_id'],
                'type' => (string) $row['type'],
                'args' => is_array($args) ? $args : [],
                'run_at' => $this->unix((string) $row['run_at']),
                'due' => $row['run_at'] <= $now,
                'attempts' => (int) $row['attempts'],
                'running' => $row['failed_at'] === null && $row['locked_until'] !== null && $row['locked_until'] > $now,
                'failed_at' => $row['failed_at'] !== null ? $this->unix((string) $row['failed_at']) : null,
                'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            ];
        }, $rows);
    }

    /**
     * How many of the store's jobs wait to run, failed ones left out.
     *
     * @param int $storeId
     * @param bool $dueOnly only those due now and not running
     * @param list<string> $types only these types; all without
     * @return int
     */
    public function waiting(int $storeId, bool $dueOnly = false, array $types = []): int
    {
        $db = $this->db();
        $select = $db->select()->from($this->table(), new \Zend_Db_Expr('COUNT(*)'))->where('store_id = ?', $storeId);
        $select->where($dueOnly ? $this->due() : 'failed_at IS NULL');
        if ($types) {
            $select->where('type IN (?)', $types);
        }

        return (int) $db->fetchOne($select);
    }

    /**
     * How many of the store's jobs failed (kept a week).
     *
     * @param int $storeId
     * @return int
     */
    public function failed(int $storeId): int
    {
        $db = $this->db();

        return (int) $db->fetchOne(
            $db->select()->from($this->table(), new \Zend_Db_Expr('COUNT(*)'))
                ->where('store_id = ?', $storeId)->where('failed_at IS NOT NULL')
        );
    }

    /**
     * When the oldest due job became due (Unix seconds); 0 when none is.
     *
     * The screen warns when jobs wait and no cron runs them.
     *
     * @return int
     */
    public function oldestDue(): int
    {
        $db = $this->db();
        $oldest = $db->fetchOne(
            $db->select()->from($this->table(), new \Zend_Db_Expr('MIN(run_at)'))->where($this->due())
        );

        return is_string($oldest) ? $this->unix($oldest) : 0;
    }

    /**
     * Runs one of the store's failed jobs again, from its first attempt.
     *
     * @param int $storeId
     * @param int $id
     * @return void
     */
    public function again(int $storeId, int $id): void
    {
        $this->db()->update(
            $this->table(),
            ['failed_at' => null, 'attempts' => 0, 'run_at' => $this->time()],
            ['job_id = ?' => $id, 'store_id = ?' => $storeId, 'failed_at IS NOT NULL']
        );
    }

    /**
     * Deletes one of the store's jobs that isn't running.
     *
     * @param int $storeId
     * @param int $id
     * @return void
     */
    public function delete(int $storeId, int $id): void
    {
        $db = $this->db();
        $db->delete($this->table(), [
            'job_id = ?' => $id,
            'store_id = ?' => $storeId,
            $db->quoteInto('(locked_until IS NULL OR locked_until < ?)', $this->time()),
        ]);
    }

    /**
     * Drops a store's jobs, or every store's with 0: it disconnected.
     *
     * @param int $storeId
     * @return void
     */
    public function cancel(int $storeId = 0): void
    {
        $this->db()->delete($this->table(), $storeId > 0 ? ['store_id = ?' => $storeId] : '');
    }

    /**
     * Due, not failed, not held by a runner.
     *
     * @return string
     */
    private function due(): string
    {
        $db = $this->db();
        $now = $this->time();

        return 'failed_at IS NULL AND ' . $db->quoteInto('run_at <= ?', $now)
            . ' AND (locked_until IS NULL OR ' . $db->quoteInto('locked_until < ?', $now) . ')';
    }

    /**
     * Now, or $offset seconds from now, as the table keeps times (UTC).
     *
     * @param int $offset
     * @return string
     */
    private function time(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    /**
     * A time from the table as Unix seconds.
     *
     * @param string $time
     * @return int
     */
    private function unix(string $time): int
    {
        return (int) strtotime($time . ' UTC');
    }

    /**
     * The database connection.
     *
     * @return AdapterInterface
     */
    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * The table's name, with the install's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName('bitlane_job');
    }
}
