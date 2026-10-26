<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class RpaClientApiKeyManagementController extends Controller
{
    public function __construct(
        private readonly RpaClientApiKeyService $keyService,
        private readonly SocialAutomationAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $keys = $this->keyService->list()->map(fn (SaClientApiKey $key) => [
            'id' => (int) $key->getAttribute('id'),
            'name' => (string) $key->getAttribute('name'),
            'key_prefix' => (string) $key->getAttribute('key_prefix'),
            'installation_id' => (string) $key->getAttribute('installation_id'),
            'created_by' => $key->created_by ? (int) $key->created_by : null,
            'created_by_name' => $key->creator?->name ?? ($key->created_by ? "User #{$key->created_by}" : null),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'is_active' => $key->isActive(),
            'created_at' => $key->created_at?->toIso8601String(),
        ]);

        return new JsonResponse([
            'ok' => true,
            'keys' => $keys,
        ]);
    }

    public function activeOrCandidate(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $data = $this->keyService->getActiveOrCandidateKey();

        return new JsonResponse([
            'ok' => true,
            'endpoint' => url('/'),
            'data' => $data,
        ]);
    }

    public function activateCandidate(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();

        $token = (string) $request->input('candidate_token', '');
        $userId = $request->user()?->id ? (int) $request->user()->id : null;

        $result = $this->keyService->activateCandidateKey($token, $userId);
        if (! ($result['ok'] ?? false)) {
            return new JsonResponse($result, 422);
        }

        return new JsonResponse($result, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();

        $name = (string) $request->input('name', '');
        $userId = $request->user()?->id ? (int) $request->user()->id : null;

        $created = $this->keyService->create($name, $userId);

        return new JsonResponse([
            'ok' => true,
            'message' => 'Client API Key created successfully. Copy the raw key now, it will not be shown again.',
            'raw_key' => $created['raw_key'],
            'key' => [
                'id' => (int) $created['key']->getAttribute('id'),
                'name' => (string) $created['key']->getAttribute('name'),
                'key_prefix' => (string) $created['key']->getAttribute('key_prefix'),
                'installation_id' => (string) $created['key']->getAttribute('installation_id'),
                'created_by' => $created['key']->created_by ? (int) $created['key']->created_by : null,
                'is_active' => true,
                'created_at' => $created['key']->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $this->access->assertCanMutate();

        $revoked = $this->keyService->revoke($id);
        if (! $revoked) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'key_not_found',
                'message' => 'Key not found or does not belong to this installation.',
            ], 404);
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'Key revoked successfully.',
        ]);
    }
}
