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

use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Support\State;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * The connection to Bitlane. The install's, encrypted with its crypt/key
 * (docs/magento-plan.md MG18):
 * - client_id, client_secret, redirect_uri: the extension's OAuth client,
 *   registered with Bitlane on the first Connect;
 * - access_token, refresh_token, expires_at, scope: the grant's tokens;
 * - project_id: the project the grant is for (null: all the user's);
 * - revoked: Bitlane ended the grant (the app was revoked there).
 * And each store group's integration id: the store in Bitlane.
 */
class ConnectionStore
{
    /**
     * @param State $state
     * @param EncryptorInterface $encryptor
     * @param Jobs $jobs
     */
    public function __construct(
        private readonly State $state,
        private readonly EncryptorInterface $encryptor,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * The stored connection, empty when there's none or it can't be read.
     *
     * A new crypt/key makes it unreadable: the store connects again.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $stored = $this->state->get('connection');
        $data = $stored === null ? null : json_decode($this->encryptor->decrypt($stored), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Merges changes into the connection; a null value removes the key.
     *
     * @param array<string,mixed> $changes
     * @return array<string, mixed> the connection
     */
    public function update(array $changes): array
    {
        $data = array_filter(array_merge($this->get(), $changes), static fn ($value) => $value !== null);
        $this->state->set('connection', $this->encryptor->encrypt((string) json_encode($data)));

        return $data;
    }

    /**
     * Ends the grant, and every store forgets Bitlane, its jobs too.
     *
     * The OAuth client stays, for the next Connect.
     *
     * @param bool $revoked whether Bitlane ended it (the admin is told)
     * @return void
     */
    public function end(bool $revoked): void
    {
        $this->update([
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scope' => null,
            'project_id' => null,
            'revoked' => $revoked ? true : null,
        ]);
        $this->state->forgetStore();
        $this->jobs->cancel();
    }

    /**
     * Whether the install has a grant.
     *
     * @return bool
     */
    public function connected(): bool
    {
        return !empty($this->get()['refresh_token']);
    }

    /**
     * The store group's integration in Bitlane; null before it's connected.
     *
     * @param int $storeId
     * @return string|null
     */
    public function integrationId(int $storeId): ?string
    {
        return $this->state->get('integration', $storeId);
    }

    /**
     * Sets or forgets the store group's integration.
     *
     * @param int $storeId
     * @param string|null $id
     * @return void
     */
    public function setIntegrationId(int $storeId, ?string $id): void
    {
        $this->state->set('integration', $id, $storeId);
    }
}
