<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Electron Client Job API — Client API Key auth only (never staff session cookies).
 */
final class ClientCampaignJobController extends Controller
{
    public function __construct(
        private readonly CampaignOrchestrator $orchestrator,
    ) {}

    public function claim(Request $request): JsonResponse
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable'], 'data' => null], 503);
        }
        $key = $this->clientKey($request);
        if ($key === null) {
            return response()->json(['ok' => false, 'errors' => ['unauthorized'], 'data' => null], 401);
        }

        $agentUuid = trim((string) $request->input('agent_uuid', ''));
        $browserIds = $request->input('browser_ids', []);
        if ($agentUuid === '' || ! is_array($browserIds)) {
            return response()->json(['ok' => false, 'errors' => ['validation_failed'], 'data' => null], 422);
        }

        $result = $this->orchestrator->claimNext(
            $agentUuid,
            $browserIds,
            (int) $key->id,
            (string) $key->installation_id,
        );

        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['claim_failed'];
            $code = in_array('agent_not_found', $errors, true) || in_array('agent_disabled', $errors, true) ? 403 : 422;

            return response()->json(['ok' => false, 'errors' => $errors, 'data' => null], $code);
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }

    public function ack(Request $request, string $id): JsonResponse
    {
        return $this->forward($request, $id, 'acknowledge');
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        return $this->forward($request, $id, 'progress');
    }

    public function result(Request $request, string $id): JsonResponse
    {
        return $this->forward($request, $id, 'result');
    }

    public function uploadArtifact(Request $request, string $id): JsonResponse
    {
        if (! SocialAutomationSchema::campaignsReady() || ! SocialAutomationSchema::has('sa_artifacts')) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }
        $key = $this->clientKey($request);
        if ($key === null) {
            return response()->json(['ok' => false, 'errors' => ['unauthorized']], 401);
        }

        $input = $request->all();
        $binary = null;
        $file = $request->file('file') ?? $request->file('artifact') ?? $request->file('screenshot');
        if ($file !== null && $file->isValid()) {
            $binary = (string) file_get_contents($file->getRealPath());
            if (! isset($input['mime']) || trim((string) $input['mime']) === '') {
                $mime = $file->getMimeType();
                if (is_string($mime) && $mime !== '') {
                    $input['mime'] = strtolower($mime);
                }
            }
        } else {
            $b64 = $request->input('content_base64');
            if (is_string($b64) && $b64 !== '') {
                $decoded = base64_decode($b64, true);
                $binary = $decoded === false ? null : $decoded;
            }
        }

        $result = $this->orchestrator->uploadCampaignArtifact(
            $id,
            is_array($input) ? $input : [],
            $binary,
            (int) $key->id,
            (string) $key->installation_id,
        );

        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['artifact_upload_failed'];
            $code = 422;
            if (in_array('job_not_found_or_forbidden', $errors, true)) {
                $code = 404;
            } elseif (in_array('stale_attempt', $errors, true) || in_array('job_cancelled', $errors, true)) {
                $code = 409;
            } elseif (in_array('schema_unavailable', $errors, true)) {
                $code = 503;
            }

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        $status = ($result['idempotent'] ?? false) ? 200 : 201;

        return response()->json([
            'ok' => true,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
            'artifact' => $result['artifact'],
        ], $status);
    }

    private function forward(Request $request, string $id, string $method): JsonResponse
    {
        if (! SocialAutomationSchema::campaignsReady()) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }
        $key = $this->clientKey($request);
        if ($key === null) {
            return response()->json(['ok' => false, 'errors' => ['unauthorized']], 401);
        }

        $result = $this->orchestrator->{$method}(
            $id,
            $request->all(),
            (int) $key->id,
            (string) $key->installation_id,
        );

        if (! ($result['ok'] ?? false)) {
            $errors = $result['errors'] ?? ['action_failed'];
            $code = 422;
            if (in_array('job_not_found_or_forbidden', $errors, true)) {
                $code = 404;
            } elseif (in_array('stale_lease_token', $errors, true)
                || in_array('stale_attempt', $errors, true)
                || in_array('attempt_fence_mismatch', $errors, true)) {
                $code = 409;
            }

            return response()->json(['ok' => false, 'errors' => $errors], $code);
        }

        return response()->json([
            'ok' => true,
            'data' => $result['data'] ?? null,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
        ]);
    }

    private function clientKey(Request $request): ?SaClientApiKey
    {
        $key = $request->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY);

        return $key instanceof SaClientApiKey ? $key : null;
    }
}
