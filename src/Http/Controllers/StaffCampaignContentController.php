<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class StaffCampaignContentController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CampaignOrchestrator $orchestrator,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installation,
    ) {}

    public function index(string $campaignId): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => true, 'data' => [], 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }

        $result = $this->campaigns->listContents($campaignId, $this->installation->resolveInstallationId());
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => $result['errors'] ?? ['campaign_not_found']], 404);
        }

        return response()->json(['ok' => true, 'data' => $result['data'] ?? []]);
    }

    public function store(Request $request, string $campaignId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->campaigns->createContent(
            $campaignId,
            $this->installation->resolveInstallationId(),
            $request->all(),
        );
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['content_create_failed'];
            $code = in_array('campaign_not_found', $errors, true) ? 404 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']], 201);
    }

    public function update(Request $request, string $campaignId, string $contentId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->campaigns->updateContent(
            $campaignId,
            $contentId,
            $this->installation->resolveInstallationId(),
            $request->all(),
        );
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['content_update_failed'];
            $code = 422;
            if (in_array('campaign_not_found', $errors, true) || in_array('content_not_found', $errors, true)) {
                $code = 404;
            }

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    /**
     * Preview mock Articles — no DB writes, no Jobs.
     */
    public function mockPreview(Request $request, string $campaignId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignShortcodeReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $count = (int) $request->input('count', 0);
        $result = $this->campaigns->mockPreview(
            $campaignId,
            $this->installation->resolveInstallationId(),
            $count,
        );
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['mock_preview_failed'];
            $code = in_array('campaign_not_found', $errors, true) ? 404 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    /**
     * Confirm-save mock Articles — atomic batch + idempotency. No Jobs / no Start.
     */
    public function mockSave(Request $request, string $campaignId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignShortcodeReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $count = (int) $request->input('count', 0);
        $idem = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
        $result = $this->campaigns->mockSave(
            $campaignId,
            $this->installation->resolveInstallationId(),
            $count,
            $idem,
        );
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['mock_save_failed'];
            $code = in_array('campaign_not_found', $errors, true) ? 404 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']], 201);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $content = $this->campaigns->findContent($id, $this->installation->resolveInstallationId());
        if ($content === null) {
            return response()->json(['ok' => false, 'errors' => ['content_not_found']], 404);
        }

        $idem = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
        if ($idem === '') {
            $idem = 'retry:'.$content->content_uuid.':'.substr(sha1((string) $request->user()?->id.':'.now()->format('YmdHi')), 0, 16);
        }

        $result = $this->orchestrator->adminRetry($content, $idem);
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => $result['errors'] ?? ['retry_failed']], 422);
        }

        // Kick dispatch for the newly pending content (creates a NEW attempt; never auto-retries old).
        $camp = $content->campaign()->first();
        if ($camp) {
            $this->orchestrator->dispatchCampaign($camp);
        }

        return response()->json([
            'ok' => true,
            'data' => $result['content'] ?? null,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
        ]);
    }

    public function block(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $content = $this->campaigns->findContent($id, $this->installation->resolveInstallationId());
        if ($content === null) {
            return response()->json(['ok' => false, 'errors' => ['content_not_found']], 404);
        }

        $idem = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
        if ($idem === '') {
            $idem = 'block:'.$content->content_uuid.':'.substr(sha1((string) $request->user()?->id.':'.now()->format('YmdHi')), 0, 16);
        }

        $result = $this->orchestrator->adminBlock($content, $idem);
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['block_failed'];
            $status = in_array('content_in_flight', $errors, true) ? 409 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $status);
        }

        return response()->json([
            'ok' => true,
            'data' => $result['content'] ?? null,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
        ]);
    }

    public function unblock(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $content = $this->campaigns->findContent($id, $this->installation->resolveInstallationId());
        if ($content === null) {
            return response()->json(['ok' => false, 'errors' => ['content_not_found']], 404);
        }

        $idem = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
        if ($idem === '') {
            $idem = 'unblock:'.$content->content_uuid.':'.substr(sha1((string) $request->user()?->id.':'.now()->format('YmdHi')), 0, 16);
        }

        $result = $this->orchestrator->adminUnblock($content, $idem);
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['unblock_failed'];
            $status = in_array('content_in_flight', $errors, true) ? 409 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $status);
        }

        $camp = $content->campaign()->first();
        if ($camp && (string) $camp->status === \Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus::Running->value) {
            $this->orchestrator->dispatchCampaign($camp);
        }

        return response()->json([
            'ok' => true,
            'data' => $result['content'] ?? null,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
        ]);
    }

    public function skip(Request $request, string $id): JsonResponse
    {
        return $this->adminTerminal($request, $id, 'skip');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->adminTerminal($request, $id, 'cancel');
    }

    private function adminTerminal(Request $request, string $id, string $action): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $content = $this->campaigns->findContent($id, $this->installation->resolveInstallationId());
        if ($content === null) {
            return response()->json(['ok' => false, 'errors' => ['content_not_found']], 404);
        }
        $idem = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', '')));
        if ($idem === '') {
            $idem = $action.':'.$content->content_uuid.':'.(string) Str::uuid();
        }
        $result = $this->orchestrator->adminSkipOrCancel($content, $action, $idem);
        if (! ($result['ok'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => $result['errors'] ?? ['action_failed']], 422);
        }

        return response()->json(['ok' => true, 'data' => $result['content'] ?? null]);
    }
}
