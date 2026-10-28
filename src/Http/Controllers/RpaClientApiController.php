<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Contracts\SaAccountRepositoryContract;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Services\ClientNotificationService;

final class RpaClientApiController extends Controller
{
    public function __construct(
        private readonly AgentRegistryService $agents,
        private readonly SessionSyncService $sessionSync,
        private readonly ?SaAccountRepositoryContract $accountsRepo = null,
        private readonly ?ClientNotificationService $notifications = null,
    ) {}

    /**
     * Validate client API Key and return installation context.
     * Raw key and key_hash are never returned.
     */
    public function validateKey(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);

        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthenticated',
            ], 401);
        }

        return new JsonResponse([
            'ok' => true,
            'client_api_key' => [
                'id' => (int) $clientKey->getAttribute('id'),
                'name' => (string) $clientKey->getAttribute('name'),
                'key_prefix' => (string) $clientKey->getAttribute('key_prefix'),
                'installation_id' => (string) $clientKey->getAttribute('installation_id'),
                'last_used_at' => $clientKey->last_used_at?->toIso8601String(),
                'created_at' => $clientKey->created_at?->toIso8601String(),
            ],
            'installation' => [
                'installation_id' => (string) $clientKey->getAttribute('installation_id'),
            ],
        ]);
    }

    /**
     * Register / connect Client under authenticated Client API Key.
     * Identity = client_api_key_id (1 key → 1 client). Device UUID not required.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $payload = $request->all();
        // Ignore client-supplied device_id — identity is the API key.
        unset($payload['device_id']);
        $payload['agent_kind'] = AgentKindPolicy::KIND_CLIENT;
        $payload['installation_id'] = (string) $clientKey->getAttribute('installation_id');

        $result = $this->agents->register($payload, credentialId: null, clientApiKeyId: (int) $clientKey->id);

        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? ($result['errors'][0] ?? 'register_failed'));
            $status = $error === ClientConnectionLease::ERROR_KEY_IN_USE ? 409 : 422;

            return new JsonResponse([
                'ok' => false,
                'error' => $error,
                'errors' => $result['errors'] ?? [$error],
                'message' => $result['message'] ?? null,
                'diagnostic' => $result['diagnostic'] ?? null,
            ], $status);
        }

        return new JsonResponse($result, 200);
    }

    /**
     * Graceful disconnect: release connection lease without revoking the API key.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }
        $token = trim((string) $request->input('connection_token', ''));
        if ($token === '') {
            return new JsonResponse(['ok' => false, 'error' => 'connection_token_required'], 422);
        }
        $released = $this->agents->releaseConnection((int) $clientKey->id, $token);

        return new JsonResponse(['ok' => true, 'released' => $released], 200);
    }

    /**
     * Heartbeat from authenticated Client (resolved by API key, not Device UUID).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $agent = $this->agents->findClientByApiKeyId((int) $clientKey->id);
        if (! $agent instanceof SaAgent) {
            // Backward-compatible: agent_uuid in body if register completed but key link lag.
            $agentUuid = trim((string) ($request->input('agent_uuid') ?? ''));
            if ($agentUuid !== '') {
                $agent = SaAgent::query()->where('agent_uuid', $agentUuid)
                    ->where('installation_id', (string) $clientKey->installation_id)->first();
            }
        }
        if (! $agent instanceof SaAgent) {
            return new JsonResponse(['ok' => false, 'error' => 'client_not_registered'], 404);
        }

        $result = $this->agents->heartbeat((string) $agent->agent_uuid, $request->all(), credentialId: null, clientApiKeyId: (int) $clientKey->id);
        if (($result['ok'] ?? false) === true) {
            $result['session_signals'] = $this->sessionSync->listSignalsForAgent($agent);
        }

        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? ($result['errors'][0] ?? 'heartbeat_failed'));
            $status = $error === ClientConnectionLease::ERROR_KEY_IN_USE ? 409 : 422;

            return new JsonResponse([
                'ok' => false,
                'error' => $error,
                'errors' => $result['errors'] ?? [$error],
            ], $status);
        }

        return new JsonResponse($result, 200);
    }

    /**
     * Heartbeat with route parameter.
     */
    public function heartbeatWithParam(Request $request, string $agentUuid): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)
            ->where('installation_id', (string) $clientKey->installation_id)->first();
        if (! $agent instanceof SaAgent) {
            return new JsonResponse(['ok' => false, 'error' => 'client_not_registered'], 404);
        }
        if ($agent->client_api_key_id !== null && (int) $agent->client_api_key_id !== (int) $clientKey->id) {
            return new JsonResponse(['ok' => false, 'error' => 'client_api_key_mismatch'], 403);
        }

        $result = $this->agents->heartbeat((string) $agent->agent_uuid, $request->all(), credentialId: null, clientApiKeyId: (int) $clientKey->id);
        if (($result['ok'] ?? false) === true) {
            $result['session_signals'] = $this->sessionSync->listSignalsForAgent($agent);
        }

        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? ($result['errors'][0] ?? 'heartbeat_failed'));
            $status = $error === ClientConnectionLease::ERROR_KEY_IN_USE ? 409 : 422;

            return new JsonResponse([
                'ok' => false,
                'error' => $error,
                'errors' => $result['errors'] ?? [$error],
            ], $status);
        }

        return new JsonResponse($result, 200);
    }

    /**
     * E4: Sync Browser Profiles from RPA Client machine.
     * Rejects any cookies, secrets, or passwords.
     */
    public function syncBrowserSessions(Request $request): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$clientKey, $agent] = $auth;

        $browsers = $request->input('browsers');
        if (! is_array($browsers)) {
            if ($request->has('browser_id')) {
                $browsers = [$request->all()];
            } else {
                return new JsonResponse(['ok' => false, 'error' => 'browsers_data_required'], 422);
            }
        }

        $installationId = (string) $clientKey->getAttribute('installation_id');
        $result = $this->sessionSync->syncClientBrowsers($agent, $browsers, $installationId);

        return new JsonResponse($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * E5: List bindable accounts for the client's installation.
     * Never returns passwords, encrypted passwords, tokens or secrets.
     */
    public function listBindableAccounts(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $installationId = trim((string) $clientKey->getAttribute('installation_id'));

        $accountsRepo = $this->accountsRepo
            ?? (function_exists('app') && app()->bound(SaAccountRepositoryContract::class)
                ? app(SaAccountRepositoryContract::class)
                : null);

        if ($accountsRepo instanceof SaAccountRepositoryContract) {
            $accountCollection = $accountsRepo->list($installationId);
            if ($request->has('platform')) {
                $platform = strtolower(trim((string) $request->input('platform')));
                if ($platform !== '') {
                    $accountCollection = $accountCollection->filter(
                        fn (SaAccount $a) => strtolower((string) $a->platform) === $platform
                    );
                }
            }

            $accounts = $accountCollection->map(static function (SaAccount $a): array {
                $email = $a->email ?? $a->declared_email;
                $emailStr = is_string($email) && trim($email) !== '' ? trim($email) : null;

                return [
                    'account_id' => (string) ($a->account_uuid ?? $a->id),
                    'platform' => strtolower((string) $a->platform),
                    'display_name' => (string) ($a->name ?? $a->label ?? $a->username ?? 'Account'),
                    'username' => $a->username ?? $a->declared_username,
                    'email' => $emailStr,
                    'site_url' => $a->getSiteUrl(),
                    'has_password' => $a->hasPassword(),
                    'identity_label' => $a->label,
                    'verified' => (bool) $a->verified,
                ];
            })->values()->all();

            return new JsonResponse([
                'ok' => true,
                'accounts' => $accounts,
            ]);
        }

        $query = SaAccount::query();
        if ($installationId !== '') {
            $query->where('installation_id', $installationId);
        }

        if ($request->has('platform')) {
            $platform = strtolower(trim((string) $request->input('platform')));
            if ($platform !== '') {
                $query->whereRaw('LOWER(platform) = ?', [$platform]);
            }
        }

        $accounts = $query->orderByDesc('updated_at')->get()->map(static function (SaAccount $a): array {
            $email = $a->email ?? $a->declared_email;
            $emailStr = is_string($email) && trim($email) !== '' ? trim($email) : null;

            return [
                'account_id' => (string) ($a->account_uuid ?? $a->id),
                'platform' => strtolower((string) $a->platform),
                'display_name' => (string) ($a->name ?? $a->label ?? $a->username ?? 'Account'),
                'username' => $a->username ?? $a->declared_username,
                'email' => $emailStr,
                'site_url' => $a->site_url ?? $a->domain,
                'has_password' => $a->hasPassword(),
                'identity_label' => $a->label,
                'verified' => (bool) $a->verified,
            ];
        })->all();

        return new JsonResponse([
            'ok' => true,
            'accounts' => $accounts,
        ]);
    }

    public function getAccount(Request $request, string $accountId): JsonResponse
    {
        $auth = $this->authorizeAccount($request, $accountId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $account = $auth;

        return new JsonResponse([
            'ok' => true,
            'account' => [
                'account_id' => (string) ($account->account_uuid ?? $account->id),
                'platform' => strtolower((string) $account->platform),
                'display_name' => $account->getAccountName(),
                'email' => $account->getDisplayEmail(),
                'site_url' => $account->getSiteUrl(),
                'password_status' => $account->hasPassword() ? 'available' : 'not_set',
            ],
        ]);
    }

    public function getAccountPassword(Request $request, string $accountId): JsonResponse
    {
        if (! $request->isSecure() && ! $this->allowsInsecureLocalPasswordAccess($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'https_required'], 400);
        }
        $auth = $this->authorizeAccount($request, $accountId);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        $account = $auth;
        if (! $account->hasPassword()) {
            return new JsonResponse(['ok' => false, 'error' => 'password_not_set'], 404);
        }

        $password = $account->getDecryptedPassword();
        if ($password === null) {
            return new JsonResponse(['ok' => false, 'error' => 'password_decryption_failed'], 500);
        }

        $response = new JsonResponse(['ok' => true, 'password' => $password]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function authorizeAccount(Request $request, string $accountId): SaAccount|JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }
        $installationId = (string) $clientKey->installation_id;
        $account = SaAccount::query()->where(function ($query) use ($accountId): void {
            $query->where('account_uuid', $accountId);
            if (ctype_digit($accountId)) {
                $query->orWhere('id', (int) $accountId);
            }
        })->first();
        if (! $account instanceof SaAccount) {
            return new JsonResponse(['ok' => false, 'error' => 'account_not_found'], 404);
        }
        if ((string) $account->installation_id !== $installationId) {
            return new JsonResponse(['ok' => false, 'error' => 'installation_forbidden'], 403);
        }

        return $account;
    }

    private function allowsInsecureLocalPasswordAccess(Request $request): bool
    {
        if (! function_exists('app') || ! app()->environment('local')) {
            return false;
        }
        $host = strtolower($request->getHost());

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test');
    }

    /**
     * E4: Delete Browser Profile metadata from SEO-OPS.
     */
    public function deleteBrowserSession(Request $request, ?string $browserId = null): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [, $agent] = $auth;

        $targetBrowserId = trim((string) ($browserId ?? $request->input('browser_id') ?? ''));
        if ($targetBrowserId === '') {
            return new JsonResponse(['ok' => false, 'error' => 'browser_id_required'], 422);
        }

        $deleted = $this->sessionSync->deleteClientBrowser($agent, $targetBrowserId);

        return new JsonResponse(['ok' => true, 'deleted' => $deleted], 200);
    }

    /**
     * E4: List active browser sessions for this client.
     */
    public function listBrowserSessions(Request $request): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [, $agent] = $auth;

        $sessions = SaBrowserSession::query()->where('agent_id', $agent->id)->get()->map(function (SaBrowserSession $s): array {
            $accountName = null;
            if ($s->account_id) {
                $account = SaAccount::query()->find($s->account_id);
                $accountName = $account?->getAccountName();
            }

            return [
                'browser_id' => $s->session_uuid,
                'account_name' => $accountName,
                'platform' => $s->platform,
                'status' => $s->browser_status,
                'last_confirmed_at' => $s->last_confirmed_at?->toIso8601String(),
            ];
        });

        return new JsonResponse(['ok' => true, 'browsers' => $sessions]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$key, $agent] = $auth;
        $service = $this->notifications ?? app(ClientNotificationService::class);

        return new JsonResponse(['ok' => true, 'notifications' => $service->pending($agent, (string) $key->installation_id)]);
    }

    public function acknowledgeNotification(Request $request, string $notificationId): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$key, $agent] = $auth;
        $service = $this->notifications ?? app(ClientNotificationService::class);
        $acked = $service->acknowledge($agent, (string) $key->installation_id, $notificationId);

        return new JsonResponse(['ok' => $acked, 'acked' => $acked], $acked ? 200 : 404);
    }

    public function confirmLogin(Request $request): JsonResponse
    {
        $auth = $this->authorizedClient($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$key, $agent] = $auth;
        $data = $request->validate([
            'browser_id' => 'required|uuid',
            'account_id' => 'required|uuid',
            'checked_at' => 'required|date',
        ]);
        $service = $this->notifications ?? app(ClientNotificationService::class);
        $result = $service->confirmLogin(
            $agent,
            (string) $key->installation_id,
            $data['browser_id'],
            $data['account_id'],
            $data['checked_at'],
        );

        return new JsonResponse($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Resolve authenticated Client by API key identity (no Device UUID).
     *
     * @return array{0: SaClientApiKey, 1: SaAgent}|JsonResponse
     */
    private function authorizedClient(Request $request): array|JsonResponse
    {
        $key = $this->resolveClientKey($request);
        if (! $key instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $agent = $this->agents->findClientByApiKeyId((int) $key->id);
        if (! $agent instanceof SaAgent) {
            return new JsonResponse(['ok' => false, 'error' => 'client_not_registered'], 404);
        }

        return [$key, $agent];
    }

    private function resolveClientKey(Request $request): ?SaClientApiKey
    {
        $clientKey = $request->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY);
        if (! $clientKey instanceof SaClientApiKey) {
            $clientKey = function_exists('app') && app()->bound(SaClientApiKey::class) ? app(SaClientApiKey::class) : null;
        }

        return $clientKey instanceof SaClientApiKey ? $clientKey : null;
    }
}
