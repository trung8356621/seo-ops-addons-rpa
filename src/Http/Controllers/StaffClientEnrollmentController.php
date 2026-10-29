<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\StaffClientEnrollmentService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;

/**
 * Staff Session APIs for Client enrollment (connection state, bootstrap Key, disconnect)
 * and Owner successful-connection inventory.
 *
 * Staff identity always comes from the authenticated server session — never from body.
 */
final class StaffClientEnrollmentController extends Controller
{
    public function __construct(
        private readonly StaffClientEnrollmentService $enrollment,
        private readonly SocialAutomationAccess $access,
    ) {}

    public function connection(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();
        $staffUserId = $this->authenticatedUserId($request);
        if ($staffUserId === null) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $state = $this->enrollment->connectionState($staffUserId);

        return new JsonResponse([
            'ok' => true,
            'endpoint' => url('/'),
            ...$state,
        ]);
    }

    public function issueBootstrapKey(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();
        $staffUserId = $this->authenticatedUserId($request);
        if ($staffUserId === null) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $result = $this->enrollment->issueBootstrapKey($staffUserId);
        $status = ($result['ok'] ?? false) ? 200 : 409;

        return new JsonResponse($result, $status);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();
        $staffUserId = $this->authenticatedUserId($request);
        if ($staffUserId === null) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        // Staff identity is session-derived above; ignore any client-supplied staff_user_id.
        $result = $this->enrollment->disconnect($staffUserId);
        $status = ($result['ok'] ?? false) ? 200 : 404;

        return new JsonResponse($result, $status);
    }

    public function ownerConnections(Request $request): JsonResponse
    {
        $this->access->assertCanAccessAdminPages();
        if ($this->authenticatedUserId($request) === null) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $connections = $this->enrollment->ownerSuccessfulConnections();

        return new JsonResponse([
            'ok' => true,
            'connections' => $connections,
            'summary' => [
                'total' => count($connections),
                'active' => count(array_filter($connections, static fn (array $row): bool => (bool) ($row['is_active'] ?? false))),
                'disconnected' => count(array_filter($connections, static fn (array $row): bool => ! (bool) ($row['is_active'] ?? false))),
            ],
            'notes' => 'Successful registrations only. Unused temporary Keys are never stored and never listed.',
        ]);
    }

    private function authenticatedUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null || ! isset($user->id)) {
            return null;
        }

        return (int) $user->id;
    }
}
