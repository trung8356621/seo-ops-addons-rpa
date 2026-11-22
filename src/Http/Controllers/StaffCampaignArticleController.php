<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\CampaignArticleAssignmentService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class StaffCampaignArticleController extends Controller
{
    public function __construct(
        private readonly CampaignArticleAssignmentService $assignments,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installation,
    ) {}

    public function index(string $id): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => true, 'data' => [], 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }

        $result = $this->assignments->listAssignments($id, $this->installation->resolveInstallationId());
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? ['campaign_not_found'],
            ], (int) ($result['http'] ?? 404));
        }

        return response()->json(['ok' => true, 'data' => $result['data'] ?? []]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->assignments->assignArticles($id, $this->installation->resolveInstallationId(), $request->all());
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['assignment_failed'];
            $code = in_array('campaign_not_found', $errors, true) ? 404 : (int) ($result['http'] ?? 422);
            if (isset($result['field_errors'])) {
                return response()->json([
                    'ok' => false,
                    'message' => $result['message'] ?? 'Validation failed.',
                    'errors' => $result['field_errors'],
                    'error_codes' => $errors,
                ], $code);
            }

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']], 201);
    }

    public function destroy(string $id, string $articleOrContentId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->assignments->removeAssignment(
            $id,
            $this->installation->resolveInstallationId(),
            $articleOrContentId,
        );
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? ['remove_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }
}
