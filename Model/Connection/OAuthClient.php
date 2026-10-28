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
use Bitlane\Search\Model\Support\Environment;
use Bitlane\Search\Model\Support\Http;
use Bitlane\Search\Model\Support\Lock;
use Bitlane\Search\Model\Support\State;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Bitlane's OAuth 2.1 flow for a confidential client (docs/oauth-plan.md,
 * docs/magento-plan.md MG2), as the plugins run it: the extension registers
 * itself (Dynamic Client Registration) the first time, sends the admin to
 * Bitlane's consent page with PKCE, and exchanges the code from PHP, so the
 * store doesn't have to be reachable from the internet. Refreshes run one at
 * a time, since several requests can find the token expired together.
 */
class OAuthClient
{
    public const SCOPES = 'projects:read engines:write widgets:write analytics:read offline_access';

    /**
     * How long a Connect waits for Bitlane's answer, in seconds.
     */
    private const PENDING = 900;

    /**
     * @param ConnectionStore $connection
     * @param State $state
     * @param Http $http
     * @param Lock $lock
     * @param Environment $environment
     * @param UrlInterface $backendUrl
     * @param StoreManagerInterface $storeManager
     * @param PackageInfo $packageInfo
     */
    public function __construct(
        private readonly ConnectionStore $connection,
        private readonly State $state,
        private readonly Http $http,
        private readonly Lock $lock,
        private readonly Environment $environment,
        private readonly UrlInterface $backendUrl,
        private readonly StoreManagerInterface $storeManager,
        private readonly PackageInfo $packageInfo
    ) {
    }

    /**
     * The API, https://api.bitlane.co unless env.php says otherwise.
     *
     * @return string
     */
    public function apiUrl(): string
    {
        return $this->environment->apiUrl();
    }

    /**
     * Where Bitlane sends the admin back: the extension's callback in the
     * admin being used, without the per-session secret key (its controller
     * is a public action, F9). The admin's path and host are part of it, so
     * another one registers a new client on the next Connect.
     *
     * @return string
     */
    public function redirectUri(): string
    {
        return $this->backendUrl->getUrl('bitlane/connect/callback', ['_nosecret' => true]);
    }

    /**
     * The authorization server's metadata (RFC 8414), kept a day.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $cached = $this->state->json('oauth_metadata');
        if (($cached['url'] ?? '') === $this->apiUrl() && ($cached['at'] ?? 0) > time() - 86400
            && is_array($cached['data'] ?? null)
        ) {
            return $cached['data'];
        }
        $res = $this->http->request('GET', $this->apiUrl() . '/.well-known/oauth-authorization-server');
        if ($res['status'] !== 200 || empty($res['body']['authorization_endpoint'])) {
            throw new BitlaneError(
                'metadata',
                (string) __('Bitlane could not be reached. Try again in a moment.'),
                $res['status']
            );
        }
        $this->state->setJson('oauth_metadata', ['url' => $this->apiUrl(), 'at' => time(), 'data' => $res['body']]);

        return $res['body'];
    }

    /**
     * The dashboard's address, for "Open in Bitlane": the consent page's host.
     *
     * @return string|null
     */
    public function appUrl(): ?string
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- Bitlane's own address, not a request's.
            $parts = parse_url((string) $this->metadata()['authorization_endpoint']);
        } catch (BitlaneError) {
            return null;
        }
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The consent page's URL for a new grant, registering the client first
     * when needed. The PKCE verifier, the expected issuer, the admin user and
     * the store wait in the state under the request's state.
     *
     * @param int $adminUserId
     * @param int $storeId the store group Connect was clicked for
     * @return string
     */
    public function authorizeUrl(int $adminUserId, int $storeId): string
    {
        $metadata = $this->metadata();
        $client = $this->client($metadata);

        $verifier = $this->random();
        $state = $this->random();
        $this->state->prune('oauth:', time() - self::PENDING);
        $this->state->setJson('oauth:' . hash('sha256', $state), [
            'verifier' => $verifier,
            'issuer' => (string) ($metadata['issuer'] ?? ''),
            'admin' => $adminUserId,
            'store' => $storeId,
        ]);

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $this->base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $this->apiUrl(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Handles Bitlane's answer on the redirect URI: checks the state (made
     * for this admin user, not expired, single use) and the issuer (RFC
     * 9207), then exchanges the code.
     *
     * @param array<string,string> $query the callback's query
     * @param int $adminUserId
     * @return int the store group Connect was clicked for
     */
    public function handleCallback(array $query, int $adminUserId): int
    {
        $key = 'oauth:' . hash('sha256', $query['state'] ?? '');
        $pending = ($query['state'] ?? '') === '' ? [] : $this->state->json($key);
        $this->state->prune('oauth:', time() - self::PENDING);
        if (!$pending || $this->state->get($key) === null || $pending['admin'] !== $adminUserId) {
            throw new BitlaneError(
                'state',
                (string) __('The connection request expired or came from elsewhere. Click Connect again.')
            );
        }
        $this->state->set($key, null);

        if (isset($query['error'])) {
            throw $query['error'] === 'access_denied'
                ? new BitlaneError('access_denied', (string) __('The connection was declined in Bitlane.'))
                : new BitlaneError($query['error'], (string) ($query['error_description'] ?? $query['error']));
        }
        if (isset($query['iss']) && $query['iss'] !== $pending['issuer']) {
            throw new BitlaneError('issuer', (string) __('The answer did not come from Bitlane.'));
        }
        if (empty($query['code'])) {
            throw new BitlaneError('no_code', (string) __('Bitlane sent no authorization code.'));
        }

        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $pending['verifier'],
            'resource' => $this->apiUrl(),
        ]);
        $this->saveTokens($tokens, ['project_id' => $tokens['project_id'] ?? null]);

        return (int) $pending['store'];
    }

    /**
     * A valid access token, refreshed when it's about to expire.
     *
     * @return string
     */
    public function accessToken(): string
    {
        $connection = $this->connection->get();
        if (empty($connection['refresh_token'])) {
            throw new BitlaneError('disconnected', (string) __('This store isn’t connected to Bitlane.'), 409);
        }
        if (!empty($connection['access_token']) && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
            return (string) $connection['access_token'];
        }

        return $this->refresh((string) ($connection['access_token'] ?? ''));
    }

    /**
     * Refreshes the tokens, unless another request did since $stale was read.
     *
     * A refused refresh token ends the connection.
     *
     * @param string $stale the access token found expired or refused
     * @return string
     */
    public function refresh(string $stale): string
    {
        return $this->lock->with('refresh', function () use ($stale): string {
            $connection = $this->connection->get();
            if (empty($connection['refresh_token'])) {
                throw new BitlaneError('disconnected', (string) __('This store isn’t connected to Bitlane.'), 409);
            }
            $renewed = !empty($connection['access_token']) && $connection['access_token'] !== $stale;
            if ($renewed && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
                return (string) $connection['access_token'];
            }

            try {
                $tokens = $this->token([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $connection['refresh_token'],
                    'resource' => $this->apiUrl(),
                ]);
            } catch (BitlaneError $error) {
                if ($error->kind === 'invalid_grant') {
                    $this->connection->end(true);
                }
                throw $error;
            }

            return $this->saveTokens($tokens);
        });
    }

    /**
     * Revokes the grant's refresh token (RFC 7009); the connection ends either way.
     *
     * @return void
     */
    public function revoke(): void
    {
        $connection = $this->connection->get();
        try {
            $metadata = $this->metadata();
            if (!empty($connection['refresh_token']) && !empty($metadata['revocation_endpoint'])) {
                $this->http->request('POST', (string) $metadata['revocation_endpoint'], [
                    'headers' => $this->clientAuth($connection),
                    'form' => ['token' => (string) $connection['refresh_token'], 'token_type_hint' => 'refresh_token'],
                ]);
            }
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- nothing to do but forget it.
        } catch (BitlaneError) {
            // Bitlane can't be reached: the store forgets the grant anyway.
        }
        $this->connection->end(false);
    }

    /**
     * The registered client, registered first when there's none for this redirect URI.
     *
     * There's none on a new install, or after the admin moved.
     *
     * @param array<string,mixed> $metadata
     * @return array<string, mixed>
     */
    private function client(array $metadata): array
    {
        $connection = $this->connection->get();
        if (!empty($connection['client_id']) && $this->redirectUri() === ($connection['redirect_uri'] ?? '')) {
            return $connection;
        }
        if (empty($metadata['registration_endpoint'])) {
            throw new BitlaneError('register', (string) __('Bitlane does not accept new connections right now.'));
        }

        $store = $this->storeManager->getDefaultStoreView();
        $home = $store instanceof Store ? (string) $store->getBaseUrl() : '';
        $res = $this->http->request('POST', (string) $metadata['registration_endpoint'], [
            'json' => [
                'redirect_uris' => [$this->redirectUri()],
                'token_endpoint_auth_method' => 'client_secret_basic',
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'client_name' => (string) __(
                    'Bitlane for Magento (%1)',
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction -- our own URL's host.
                    (string) parse_url($this->redirectUri(), PHP_URL_HOST)
                ),
                // Bitlane takes an https address only: a store without one
                // registers without it.
                'client_uri' => str_starts_with($home, 'https://') ? $home : '',
                'software_id' => 'bitlane-magento',
                'software_version' => $this->packageInfo->getVersion('Bitlane_Search') ?: '0.0.0',
            ],
        ]);
        if (!in_array($res['status'], [200, 201], true) || empty($res['body']['client_id'])) {
            throw new BitlaneError(
                'register',
                (string) ($res['body']['error_description'] ?? __('Bitlane refused to register this store.')),
                $res['status']
            );
        }

        return $this->connection->update([
            'client_id' => $res['body']['client_id'],
            'client_secret' => $res['body']['client_secret'] ?? '',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Calls the token endpoint with the client's credentials.
     *
     * A refusal throws with the OAuth error code as its kind.
     *
     * @param array<string,string> $params
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        $res = $this->http->request('POST', (string) $this->metadata()['token_endpoint'], [
            'headers' => $this->clientAuth($this->connection->get()),
            'form' => $params,
        ]);
        if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
            throw new BitlaneError(
                (string) ($res['body']['error'] ?? 'token'),
                (string) ($res['body']['error_description'] ?? __('Bitlane refused the connection.')),
                $res['status']
            );
        }

        return $res['body'];
    }

    /**
     * Stores a token answer and returns the access token.
     *
     * @param array<string,mixed> $tokens
     * @param array<string,mixed> $extra
     * @return string
     */
    private function saveTokens(array $tokens, array $extra = []): string
    {
        $connection = $this->connection->update(array_merge([
            'access_token' => $tokens['access_token'],
            // A refresh without rotation keeps the one it has.
            'refresh_token' => $tokens['refresh_token'] ?? $this->connection->get()['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($tokens['expires_in'] ?? 3600),
            'scope' => $tokens['scope'] ?? self::SCOPES,
            'revoked' => null,
        ], $extra));

        return (string) $connection['access_token'];
    }

    /**
     * HTTP Basic authentication with the client's id and secret (RFC 6749 2.3.1).
     *
     * @param array<string,mixed> $connection
     * @return array<string, string>
     */
    private function clientAuth(array $connection): array
    {
        $credentials = rawurlencode((string) ($connection['client_id'] ?? ''))
            . ':' . rawurlencode((string) ($connection['client_secret'] ?? ''));

        return ['Authorization' => 'Basic ' . base64_encode($credentials)];
    }

    /**
     * 32 random bytes, base64url: a PKCE verifier or a state.
     *
     * @return string
     */
    private function random(): string
    {
        return $this->base64url(random_bytes(32));
    }

    /**
     * Base64 for URLs, without padding (RFC 4648 §5).
     *
     * @param string $bytes
     * @return string
     */
    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
