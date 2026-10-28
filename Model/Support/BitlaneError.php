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

/**
 * Something Bitlane, an engine, the network or the store refused, with a
 * message for the admin: an OAuth error code or one of ours as $kind, the
 * HTTP status when there was an answer, Bitlane's field errors, and how long
 * the answer asked to wait (Retry-After).
 */
class BitlaneError extends \RuntimeException
{
    /**
     * @param string $kind
     * @param string $message
     * @param int|null $status
     * @param array<string,mixed>|null $errors
     * @param int $retryAfter
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $errors = null,
        public readonly int $retryAfter = 0
    ) {
        parent::__construct($message);
    }

    /**
     * The error as the admin screen reads it.
     *
     * @return array{code: string, message: string, status: int|null, errors: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->kind,
            'message' => $this->getMessage(),
            'status' => $this->status,
            'errors' => $this->errors,
        ];
    }
}
