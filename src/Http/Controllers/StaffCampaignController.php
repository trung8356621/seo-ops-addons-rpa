<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
use Omnichannel\Addons\SocialAutomation\Services\CampaignV2PlanService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class StaffCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installation,
    ) {}

    public function index(): JsonResponse
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => true, 'data' => [], 'pending' => 'PENDING SCHEMA / MIGRATION']);
        }
        $installationId = $this->installation->resolveInstallationId();

        return response()->json(['ok' => true, 'data' => $this->campaigns->list($installationId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $result = $this->campaigns->create(
            $request->all(),
            $this->installation->resolveInstallationId(),
            $request->user()?->id ? (int) $request->user()->id : null,
        );
        if (! ($result['ok'] ?? false)) {
            if (isset($result['field_errors'])) {
                return response()->json([
                    'ok' => false,
                    'message' => $result['message'] ?? 'Validation failed.',
                    'errors' => $result['field_errors'],
                    'error_codes' => $result['errors'] ?? ['validation_failed'],
                ], (int) ($result['http'] ?? 422));
            }

            return response()->json(['ok' => false, 'errors' => $result['errors'] ?? ['create_failed']], 422);
        }

        return response()->json(['ok' => true, 'data' => $result['data']], 201);
    }

    public function show(string $id): JsonResponse
    {
        $this->access->assertCanAccess();
        $detail = $this->campaigns->detail($id, $this->installation->resolveInstallationId());
        if ($detail === null) {
            return response()->json(['ok' => false, 'errors' => ['campaign_not_found']], 404);
        }

        return response()->json(['ok' => true, 'data' => $detail]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $result = $this->campaigns->update($id, $this->installation->resolveInstallationId(), $request->all());
        if (! ($result['ok'] ?? false)) {
            if (isset($result['field_errors'])) {
                $code = in_array('campaign_not_found', $result['errors'] ?? [], true)
                    ? 404
                    : (int) ($result['http'] ?? 422);

                return response()->json([
                    'ok' => false,
                    'message' => $result['message'] ?? 'Validation failed.',
                    'errors' => $result['field_errors'],
                    'error_codes' => $result['errors'] ?? ['update_failed'],
                ], $code);
            }
            $code = in_array('campaign_not_found', $result['errors'] ?? [], true) ? 404 : 422;

            return response()->json(['ok' => false, 'errors' => $result['errors'] ?? ['update_failed']], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    public function start(string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $result = $this->campaigns->start($id, $this->installation->resolveInstallationId());

        return $this->actionResponse($result);
    }

    public function pause(string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $result = $this->campaigns->pause($id, $this->installation->resolveInstallationId());

        return $this->actionResponse($result);
    }

    public function resume(string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $result = $this->campaigns->resume($id, $this->installation->resolveInstallationId());

        return $this->actionResponse($result);
    }

    public function cancel(string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        $result = $this->campaigns->cancel($id, $this->installation->resolveInstallationId());

        return $this->actionResponse($result);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignSoftDeleteReady()) {
            return response()->json([
                'ok' => false,
                'success' => false,
                'code' => 'SCHEMA_UNAVAILABLE',
                'reason' => 'schema_unavailable',
                'errors' => ['schema_unavailable'],
            ], 503);
        }

        $result = $this->campaigns->delete(
            $id,
            $this->installation->resolveInstallationId(),
            $request->user()?->id ? (int) $request->user()->id : null,
        );
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'success' => false,
                'code' => $result['code'] ?? 'CAMPAIGN_DELETE_BLOCKED',
                'reason' => $result['reason'] ?? 'delete_blocked',
                'errors' => $result['errors'] ?? ['delete_blocked'],
            ], (int) ($result['http'] ?? 409));
        }

        return response()->json([
            'ok' => true,
            'success' => true,
            'campaign_id' => $result['campaign_id'],
            'deleted_at' => $result['deleted_at'],
        ]);
    }

    public function downloadArtifact(string $artifactUuid): mixed
    {
        $this->access->assertCanAccess();
        if (! SocialAutomationSchema::campaignsReady() || ! SocialAutomationSchema::has('sa_artifacts')) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $uuid = trim($artifactUuid);
        if (! Str::isUuid($uuid)) {
            return response()->json(['ok' => false, 'errors' => ['artifact_not_found']], 404);
        }

        $artifact = SaArtifact::query()->where('artifact_uuid', $uuid)->first();
        if (! $artifact instanceof SaArtifact || ! is_string($artifact->storage_path) || $artifact->storage_path === '') {
            return response()->json(['ok' => false, 'errors' => ['artifact_not_found']], 404);
        }

        if (str_contains($artifact->storage_path, '..') || ! str_starts_with($artifact->storage_path, 'social-automation/artifacts/')) {
            return response()->json(['ok' => false, 'errors' => ['artifact_not_found']], 404);
        }

        $job = SaJob::query()->find($artifact->job_id);
        if (! $job instanceof SaJob || $job->campaign_id === null) {
            return response()->json(['ok' => false, 'errors' => ['artifact_not_found']], 404);
        }

        $installationId = $this->installation->resolveInstallationId();
        $campaign = SaCampaign::query()
            ->notDeleted()
            ->where('id', $job->campaign_id)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return response()->json(['ok' => false, 'errors' => ['artifact_not_found']], 404);
        }

        $disk = Storage::disk($artifact->storage_disk ?: 'local');
        if (! $disk->exists($artifact->storage_path)) {
            return response()->json(['ok' => false, 'errors' => ['artifact_file_missing']], 404);
        }

        return $disk->response(
            $artifact->storage_path,
            basename($artifact->storage_path),
            [
                'Content-Type' => (string) ($artifact->mime ?: 'application/octet-stream'),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Checksum-Sha256' => (string) ($artifact->checksum_sha256 ?? ''),
            ],
        );
    }

    public function manualTestActionTag(Request $request, string $id, string $planTagId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $installationId = $this->installation->resolveInstallationId();
        $campaign = SaCampaign::query()
            ->notDeleted()
            ->where('campaign_uuid', $id)
            ->where('installation_id', $installationId)
            ->first();
        if (! $campaign instanceof SaCampaign) {
            return response()->json(['ok' => false, 'errors' => ['campaign_not_found']], 404);
        }

        $planTag = SaCampaignActionTag::query()
            ->where('campaign_id', $campaign->id)
            ->where('plan_tag_uuid', $planTagId)
            ->first();
        if (! $planTag instanceof SaCampaignActionTag) {
            return response()->json(['ok' => false, 'errors' => ['action_tag_not_found']], 404);
        }

        $accountId = $request->filled('account_id') ? (int) $request->input('account_id') : null;
        $result = app(CampaignOrchestrator::class)->dispatchManualActionTagTest($campaign, $planTag, $accountId);
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['manual_test_dispatch_failed'];
            $code = in_array('campaign_not_dispatchable', $errors, true) ? 409 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'job' => $result['job']], 201);
    }

    public function updateActionTagInterval(Request $request, string $id, string $planTagId): JsonResponse
    {
        $this->access->assertCanManageCampaigns();
        if (! SocialAutomationSchema::campaignV2Ready()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        if (! $request->exists('interval_minutes')) {
            return response()->json(['ok' => false, 'errors' => ['interval_minutes_required']], 422);
        }

        $raw = $request->input('interval_minutes');
        if (is_bool($raw) || is_array($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return response()->json(['ok' => false, 'errors' => ['invalid_interval_minutes']], 422);
        }
        $interval = (int) $raw;
        if ($interval < 0 || $interval > CampaignV2PlanService::MAX_INTERVAL_MINUTES) {
            return response()->json(['ok' => false, 'errors' => ['invalid_interval_minutes']], 422);
        }

        $result = app(CampaignV2PlanService::class)->updateActionTagInterval(
            $id,
            $planTagId,
            $interval,
            $this->installation->resolveInstallationId(),
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? ['interval_update_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json($result['data'], 200);
    }

    /**
     * @param  array{ok: bool, data?: array<string, mixed>, errors?: list<string>}  $result
     */
    private function actionResponse(array $result): JsonResponse
    {
        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['action_failed'];
            $code = in_array('campaign_not_found', $errors, true) ? 404 : 422;

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }
}
