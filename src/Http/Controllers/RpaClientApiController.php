<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;

final class RpaClientApiController extends Controller
{
    public function __construct(
        private readonly AgentRegistryService $agents,
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
     * Register device under authenticated Client API Key.
     * Idempotent by device_id.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $payload = $request->all();
        $payload['agent_kind'] = AgentKindPolicy::KIND_CLIENT;

        $result = $this->agents->register($payload, credentialId: null, clientApiKeyId: (int) $clientKey->id);

        return new JsonResponse($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Heartbeat from client machine.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $clientKey = $this->resolveClientKey($request);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $agentUuid = (string) ($request->input('agent_uuid') ?? $request->input('device_id') ?? '');
        if ($agentUuid === '') {
            return new JsonResponse(['ok' => false, 'error' => 'device_identifier_required'], 422);
        }

        $result = $this->agents->heartbeat($agentUuid, $request->all(), credentialId: null, clientApiKeyId: (int) $clientKey->id);

        return new JsonResponse($result, ($result['ok'] ?? false) ? 200 : 422);
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

        $result = $this->agents->heartbeat($agentUuid, $request->all(), credentialId: null, clientApiKeyId: (int) $clientKey->id);

        return new JsonResponse($result, ($result['ok'] ?? false) ? 200 : 422);
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
