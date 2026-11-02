<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\CampaignService;
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
