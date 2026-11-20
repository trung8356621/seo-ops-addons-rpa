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
