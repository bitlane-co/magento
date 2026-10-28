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

namespace Bitlane\Search\Controller\Adminhtml\Action;

use Bitlane\Search\Model\Screen\Actions;
use Bitlane\Search\Model\Support\BitlaneError;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\Group;
use Psr\Log\LoggerInterface;

/**
 * The Bitlane screen's JSON actions (?action=…&bitlane_store=<store group>):
 * GET to read, POST with a JSON body and the admin's form key to change
 * something. Seeing needs Bitlane_Search::view, changing
 * Bitlane_Search::manage (docs/magento-plan.md MG14).
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Bitlane_Search::view';

    /**
     * The actions that change something.
     */
    private const MANAGE = [
        'authorize', 'project', 'setup', 'disconnect', 'job_again', 'job_delete', 'resync', 'schedule_indexers',
        'storefront',
    ];

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param JsonSerializer $json
     * @param Actions $actions
     * @param LoggerInterface $logger
     * @param Session $session
     * @param HttpRequest $http the request, with its method and body
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly JsonSerializer $json,
        private readonly Actions $actions,
        private readonly LoggerInterface $logger,
        private readonly Session $session,
        private readonly HttpRequest $http
    ) {
        parent::__construct($context);
    }

    /**
     * Answers the action as JSON: its result, or the error with its status.
     *
     * An error is {code, message, status, errors}.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        try {
            $body = $this->run((string) $this->getRequest()->getParam('action'));
        } catch (BitlaneError $e) {
            $result->setHttpResponseCode($e->status && $e->status >= 400 && $e->status < 500 ? $e->status : 502);
            $body = $e->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Bitlane: ' . $e->getMessage(), ['exception' => $e]);
            $result->setHttpResponseCode(500);
            $body = (new BitlaneError(
                'unexpected',
                (string) __('Something went wrong. The details are in var/log/system.log.')
            ))->toArray();
        }

        return $result->setData($body);
    }

    /**
     * Runs an action.
     *
     * @param string $action
     * @return array<mixed>
     */
    private function run(string $action): array
    {
        if (in_array($action, self::MANAGE, true)) {
            if (!$this->_authorization->isAllowed('Bitlane_Search::manage')) {
                throw new BitlaneError('forbidden', (string) __('You do not have permission to do this.'), 403);
            }
            if (!$this->http->isPost()) {
                throw new BitlaneError('method', (string) __('This action needs a POST request.'), 405);
            }
        }

        return match ($action) {
            'status' => $this->actions->status($this->store()),
            'authorize' => [
                'authorize_url' => $this->actions->authorize(
                    $this->requireStore(),
                    (int) $this->session->getUser()?->getId()
                ),
            ],
            'projects' => $this->actions->projects(),
            'project' => $this->connectStore(),
            'engines' => $this->actions->engines($this->requireStore()),
            'setup' => $this->actions->setup($this->requireStore(), $this->input()),
            'disconnect' => $this->disconnect(),
            // Runs what's queued already, as the cron would: seeing the screen is enough.
            'run' => $this->actions->run($this->requireStore()),
            'jobs' => $this->actions->jobs($this->requireStore()),
            'job_again' => $this->actions->jobAgain($this->requireStore(), (int) ($this->input()['id'] ?? 0)),
            'job_delete' => $this->actions->jobDelete($this->requireStore(), (int) ($this->input()['id'] ?? 0)),
            'resync' => $this->actions->resync(
                $this->requireStore(),
                ((string) ($this->input()['source'] ?? '')) ?: null
            ),
            'schedule_indexers' => $this->actions->schedule($this->requireStore()),
            'storefront' => $this->actions->storefront($this->requireStore(), !empty($this->input()['enabled'])),
            default => throw new BitlaneError('unknown_action', (string) __('This action doesn’t exist.'), 404),
        };
    }

    /**
     * Connects the store in the project picked, then answers the status.
     *
     * @return array<string, mixed>
     */
    private function connectStore(): array
    {
        $store = $this->requireStore();
        $this->actions->connectStore($store, (string) ($this->input()['project_id'] ?? ''));

        return $this->actions->status($store);
    }

    /**
     * Disconnects the store, then answers the status.
     *
     * @return array<string, mixed>
     */
    private function disconnect(): array
    {
        $store = $this->requireStore();
        $this->actions->disconnect($store);

        return $this->actions->status($store);
    }

    /**
     * The store the screen works on; null when it has to pick one.
     *
     * @return Group|null
     */
    private function store(): ?Group
    {
        return $this->actions->store((int) $this->getRequest()->getParam('bitlane_store') ?: null);
    }

    /**
     * The store the screen works on, which a change needs.
     *
     * @return Group
     */
    private function requireStore(): Group
    {
        return $this->store() ?? throw new BitlaneError('no_store', (string) __('Choose a store first.'), 400);
    }

    /**
     * The action's JSON body.
     *
     * @return array<string, mixed>
     */
    private function input(): array
    {
        try {
            $data = $this->json->unserialize((string) $this->http->getContent());
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
