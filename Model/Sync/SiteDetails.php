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
use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Support\State;
use Magento\Store\Model\Group;

/**
 * The store's address and name in Bitlane.
 *
 * A store moved to another address keeps its site there: the job
 * site_details sends them to PATCH /api/integrations/:id, which refuses an
 * address another site of the project has (the job then fails, in the Jobs
 * tab). Connecting with the new address would make another site.
 */
class SiteDetails implements HandlerInterface
{
    public const JOB = 'site_details';

    /**
     * @param State $state
     * @param Site $site
     * @param BitlaneApi $api
     * @param ConnectionStore $connection
     * @param Jobs $jobs
     */
    public function __construct(
        private readonly State $state,
        private readonly Site $site,
        private readonly BitlaneApi $api,
        private readonly ConnectionStore $connection,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * Queues the update when the address or name changed since Bitlane got them.
     *
     * The first look only notes them (connecting sent them).
     *
     * @param Group $store
     * @return void
     */
    public function check(Group $store): void
    {
        $storeId = (int) $store->getId();
        if (!$this->connection->integrationId($storeId)) {
            return;
        }
        $kept = $this->state->json('site_details', $storeId);
        if (!$kept) {
            $this->state->setJson('site_details', $this->details($store), $storeId);
        } elseif ($kept !== $this->details($store)) {
            $this->jobs->push($storeId, self::JOB, [], 0, self::JOB);
        }
    }

    /**
     * The job: Bitlane gets them as they are now.
     *
     * @param int $storeId
     * @param array<mixed> $args
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        $id = $this->connection->integrationId($storeId);
        $store = $this->site->store($storeId);
        if (!$id || !$store) {
            return;
        }
        $details = $this->details($store);
        $this->api->request('PATCH', '/api/integrations/' . rawurlencode($id), $details);
        $this->state->setJson('site_details', $details, $storeId);
    }

    /**
     * The store's address and name as Bitlane keeps them.
     *
     * @param Group $store
     * @return array{site_url: string, site_name: string}
     */
    private function details(Group $store): array
    {
        return ['site_url' => $this->site->url($store), 'site_name' => $this->site->name($store)];
    }
}
