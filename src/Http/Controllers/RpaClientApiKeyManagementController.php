<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\ClientApiKeyAssignmentPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

final class RpaClientApiKeyManagementController extends Controller
{
    public function __construct(
        private readonly RpaClientApiKeyService $keyService,
        private readonly SocialAutomationAccess $access,
        private readonly ClientApiKeyAssignmentPolicy $assignmentPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess($this->actor($request));

        $keys = $this->keyService->list()->map(fn (SaClientApiKey $key) => $this->serializeKey($key));

        return new JsonResponse([
            'ok' => true,
            'keys' => $keys,
        ]);
    }

    public function assignableUsers(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanAssignClientKeys($actor);
        if (! $actor instanceof User) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        return new JsonResponse([
            'ok' => true,
            'users' => $this->assignmentPolicy->listAssignableUsers($actor),
        ]);
    }

    public function activeOrCandidate(Request $request): JsonResponse
    {
        $this->access->assertCanAccess($this->actor($request));

        $data = $this->keyService->getActiveOrCandidateKey();

        return new JsonResponse([
            'ok' => true,
            'endpoint' => url('/'),
            'data' => $data,
        ]);
    }

    public function activateCandidate(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanMutate($actor);

        $token = (string) $request->input('candidate_token', '');
        $userId = $actor instanceof User ? (int) $actor->id : null;

        $result = $this->keyService->activateCandidateKey($token, $userId);
        if (! ($result['ok'] ?? false)) {
            return new JsonResponse($result, 422);
        }

        // Never echo candidate raw again beyond activation payload contract.
        return new JsonResponse($result, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertCanMutate($actor);
        if (! $actor instanceof User) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        // Never trust client-supplied ownership fields as the minting actor.
        $request->request->remove('created_by');
        $request->request->remove('issued_by');

        $name = (string) $request->input('name', '');
        $assignedRaw = $request->input('assigned_to_user_id', null);
        $assignedTo = null;
        if ($assignedRaw !== null && $assignedRaw !== '') {
            if (! is_numeric($assignedRaw)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => ClientApiKeyAssignmentPolicy::ERROR_ASSIGNED_USER_INVALID,
                    'message' => 'assigned_to_user_id must be a positive integer.',
                ], 422);
            }
            $assignedTo = (int) $assignedRaw;
        }

        $ownership = $this->assignmentPolicy->resolveCreateOwnership($actor, $assignedTo);
        if (! ($ownership['ok'] ?? false)) {
            $error = (string) ($ownership['error'] ?? 'assignment_forbidden');
            $status = $error === ClientApiKeyAssignmentPolicy::ERROR_ASSIGNMENT_FORBIDDEN ? 403 : 422;

            return new JsonResponse([
                'ok' => false,
                'error' => $error,
                'message' => (string) ($ownership['message'] ?? 'Client API Key assignment rejected.'),
            ], $status);
        }

        $created = $this->keyService->create(
            $name,
            $ownership['owner_user_id'] ?? null,
            $ownership['issued_by_user_id'] ?? null,
        );

        return new JsonResponse([
            'ok' => true,
            'message' => 'Client API Key created successfully. Copy the raw key now, it will not be shown again.',
            'raw_key' => $created['raw_key'],
            'key' => $this->serializeKey($created['key']),
        ], 201);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $this->access->assertCanMutate($this->actor($request));

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

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeKey(SaClientApiKey $key): array
    {
        $createdBy = $key->getAttribute('created_by');
        $issuedBy = $key->getAttribute('issued_by');

        return [
            'id' => (int) $key->getAttribute('id'),
            'name' => (string) $key->getAttribute('name'),
            'key_prefix' => (string) $key->getAttribute('key_prefix'),
            'installation_id' => (string) $key->getAttribute('installation_id'),
            'created_by' => $createdBy ? (int) $createdBy : null,
            'created_by_name' => $this->relatedUserName($key, 'creator', $createdBy),
            'issued_by' => $issuedBy ? (int) $issuedBy : null,
            'issued_by_name' => $this->relatedUserName($key, 'issuer', $issuedBy),
            'last_used_at' => $this->isoTimestamp($key->getAttribute('last_used_at')),
            'revoked_at' => $this->isoTimestamp($key->getAttribute('revoked_at')),
            'is_active' => $key->isActive(),
            'created_at' => $this->isoTimestamp($key->getAttribute('created_at')),
        ];
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function relatedUserName(SaClientApiKey $key, string $relation, mixed $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        try {
            $related = $key->{$relation};
            if ($related && isset($related->name) && is_string($related->name) && $related->name !== '') {
                return $related->name;
            }
        } catch (\Throwable) {
            // Unit tests / unbound container: fall back to id label.
        }

        return 'User #'.(int) $userId;
    }
}
