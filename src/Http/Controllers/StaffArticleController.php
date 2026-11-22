<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\ArticleLibraryService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class StaffArticleController extends Controller
{
    public function __construct(
        private readonly ArticleLibraryService $articles,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => true, 'data' => [], 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }

        $filters = $request->only([
            'site_id',
            'site_key',
            'action_tag_id',
            'action_tag_key',
            'status',
            'search',
        ]);
        $limit = (int) $request->query('limit', 100);

        return response()->json([
            'ok' => true,
            'data' => $this->articles->list($this->installation->resolveInstallationId(), $filters, $limit),
        ]);
    }

    public function compatible(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => true, 'data' => [], 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }

        $filters = $request->only([
            'campaign_id',
            'site_id',
            'site_key',
            'action_tag_id',
            'action_tag_key',
            'status',
            'search',
            'exclude_assigned',
        ]);
        $limit = (int) $request->query('limit', 100);

        $result = $this->articles->listCompatible($this->installation->resolveInstallationId(), $filters, $limit);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? ['compatible_query_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json(['ok' => true, 'data' => $result['data'] ?? []]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->articles->create(
            $request->all(),
            $this->installation->resolveInstallationId(),
            $request->user()?->id ? (int) $request->user()->id : null,
        );
        if (! ($result['ok'] ?? false)) {
            return $this->errorResponse($result, 422);
        }

        return response()->json(['ok' => true, 'data' => $result['data']], 201);
    }

    public function show(string $id): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $detail = $this->articles->detail($id, $this->installation->resolveInstallationId());
        if ($detail === null) {
            return response()->json(['ok' => false, 'errors' => ['article_not_found']], 404);
        }

        return response()->json(['ok' => true, 'data' => $detail]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->articles->update($id, $this->installation->resolveInstallationId(), $request->all());
        if (! ($result['ok'] ?? false)) {
            return $this->errorResponse($result, 422);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::articleLibraryReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->articles->delete($id, $this->installation->resolveInstallationId());
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? ['delete_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function errorResponse(array $result, int $defaultCode): JsonResponse
    {
        $errors = $result['errors'] ?? ['validation_failed'];
        $code = in_array('article_not_found', $errors, true)
            ? 404
            : (int) ($result['http'] ?? $defaultCode);

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
}
