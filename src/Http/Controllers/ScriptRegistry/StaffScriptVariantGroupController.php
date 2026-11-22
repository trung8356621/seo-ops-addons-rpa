<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\ScriptVariantGroupService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Staff Session API for Script Registry Variant Groups & Ordered Fallback Priority.
 */
final class StaffScriptVariantGroupController extends Controller
{
    public function __construct(
        private readonly ScriptVariantGroupService $groups,
        private readonly SocialAutomationAccess $access,
    ) {}

    public function index(): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => true, 'groups' => [], 'count' => 0, 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }

        $groups = $this->groups->list();

        return response()->json([
            'ok' => true,
            'groups' => $groups,
            'count' => count($groups),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $group = $this->groups->detail($id);
        if ($group === null) {
            return response()->json(['ok' => false, 'error' => 'variant_group_not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'group' => $group,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $result = $this->groups->create(
            $request->all(),
            $request->user()?->id ? (int) $request->user()->id : null,
        );
        if (! ($result['ok'] ?? false)) {
            $payload = [
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'create_failed'),
                'errors' => $result['errors'] ?? ['create_failed'],
            ];
            if (isset($result['conflict'])) {
                $payload['conflict'] = $result['conflict'];
            }

            return response()->json($payload, (int) ($result['http'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'group' => $result['data'],
        ], 201);
    }

    public function addVariant(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanMutate();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $result = $this->groups->addVariant($id, $request->all());
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'variant_add_failed'),
                'errors' => $result['errors'] ?? ['variant_add_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'group' => $result['data'],
        ], 201);
    }

    public function updateVariant(Request $request, string $id, string $variantId): JsonResponse
    {
        $this->access->assertCanMutate();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $result = $this->groups->updateVariant($id, $variantId, $request->all());
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'variant_update_failed'),
                'errors' => $result['errors'] ?? ['variant_update_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'group' => $result['data'],
        ]);
    }

    public function removeVariant(string $id, string $variantId): JsonResponse
    {
        $this->access->assertCanMutate();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $result = $this->groups->removeVariant($id, $variantId);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'variant_remove_failed'),
                'errors' => $result['errors'] ?? ['variant_remove_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'group' => $result['data'],
        ]);
    }

    public function reorder(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanMutate();
        if (! SocialAutomationSchema::scriptVariantGroupsReady()) {
            return response()->json(['ok' => false, 'error' => 'schema_unavailable'], 503);
        }

        $variantIds = $request->input('variant_ids');
        if (! is_array($variantIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'variant_ids_required',
                'errors' => ['variant_ids_required'],
            ], 422);
        }

        $result = $this->groups->reorder($id, $variantIds);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'reorder_failed'),
                'errors' => $result['errors'] ?? ['reorder_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'group' => $result['data'],
        ]);
    }
}
