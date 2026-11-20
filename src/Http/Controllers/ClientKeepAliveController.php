<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveAckService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveCampaignFeedService;
use Omnichannel\Addons\SocialAutomation\Services\KeepAliveNodeService;

/**
 * Keep Alive Node HTTP surface (Client API Key only).
 * Registration does not grant Campaign access. Assignments are explicit-only.
 * Event/artifact ACKs are delivery confirmations — not Job execution ACKs.
 */
final class ClientKeepAliveController extends Controller
{
    public function __construct(
        private readonly KeepAliveNodeService $keepalive,
        private readonly KeepAliveCampaignFeedService $feed,
        private readonly KeepAliveAckService $acks,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $result = $this->keepalive->register($key, $request->all());
        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'register_failed'),
                'errors' => $result['errors'] ?? ['register_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    public function assignments(Request $request): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $nodeId = trim((string) ($request->query('node_id') ?? $request->input('node_id') ?? ''));
        $result = $this->keepalive->listAssignments(
            $key,
            $nodeId,
            $request->query('tenant_id') ?? $request->input('tenant_id'),
        );

        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'assignments_failed'),
                'errors' => $result['errors'] ?? ['assignments_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    public function snapshot(Request $request, string $id): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $result = $this->feed->snapshot(
            $key,
            $id,
            trim((string) ($request->query('node_id') ?? $request->input('node_id') ?? '')),
            $request->query('assignment_generation') ?? $request->input('assignment_generation'),
            $request->query('tenant_id') ?? $request->input('tenant_id'),
        );

        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'snapshot_failed'),
                'errors' => $result['errors'] ?? ['snapshot_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $result = $this->feed->events(
            $key,
            $id,
            trim((string) ($request->query('node_id') ?? $request->input('node_id') ?? '')),
            $request->query('assignment_generation') ?? $request->input('assignment_generation'),
            $request->query('from_sequence') ?? $request->input('from_sequence'),
            $request->query('cursor') ?? $request->input('cursor'),
            $request->query('to_sequence') ?? $request->input('to_sequence'),
            $request->query('limit') ?? $request->input('limit'),
            $request->query('tenant_id') ?? $request->input('tenant_id'),
        );

        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'events_failed'),
                'errors' => $result['errors'] ?? ['events_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    public function ackEvents(Request $request): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $result = $this->acks->ackEvents($key, $request->all());
        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'events_ack_failed'),
                'errors' => $result['errors'] ?? ['events_ack_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    public function ackArtifacts(Request $request): JsonResponse
    {
        $key = $this->clientKey($request);
        if ($key === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'errors' => ['unauthorized'],
            ], 401);
        }

        $result = $this->acks->ackArtifact($key, $request->all());
        if (! ($result['ok'] ?? false)) {
            return new JsonResponse([
                'ok' => false,
                'error' => $result['error'] ?? ($result['errors'][0] ?? 'artifact_ack_failed'),
                'errors' => $result['errors'] ?? ['artifact_ack_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return new JsonResponse(array_merge(['ok' => true], $result['data'] ?? []), 200);
    }

    private function clientKey(Request $request): ?SaClientApiKey
    {
        $key = $request->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY);

        return $key instanceof SaClientApiKey ? $key : null;
    }
}
