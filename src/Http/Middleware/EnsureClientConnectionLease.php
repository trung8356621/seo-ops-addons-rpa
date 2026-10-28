<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Registry\AgentRegistryService;
use Symfony\Component\HttpFoundation\Response;

/**
 * SB3 — require active Client connection lease (connection_token) after API-key auth.
 */
final class EnsureClientConnectionLease
{
    public function __construct(
        private readonly AgentRegistryService $agents,
        private readonly ClientConnectionLease $lease = new ClientConnectionLease,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clientKey = $request->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY);
        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthenticated',
                'errors' => ['unauthenticated'],
            ], 401);
        }

        $token = trim((string) ($request->input('connection_token')
            ?? $request->header('X-Connection-Token')
            ?? ''));
        if ($token === '') {
            return new JsonResponse([
                'ok' => false,
                'error' => 'connection_token_required',
                'errors' => ['connection_token_required'],
            ], 422);
        }

        $agent = $this->agents->findClientByApiKeyId((int) $clientKey->id);
        if (! $agent instanceof SaAgent) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'client_not_registered',
                'errors' => ['client_not_registered'],
            ], 404);
        }

        if (! $this->lease->isActive($agent->connection_token, $agent->connection_lease_expires_at)) {
            return new JsonResponse([
                'ok' => false,
                'error' => ClientConnectionLease::ERROR_KEY_IN_USE,
                'errors' => [ClientConnectionLease::ERROR_KEY_IN_USE],
            ], 409);
        }

        if ($agent->connection_token === null || ! hash_equals((string) $agent->connection_token, $token)) {
            return new JsonResponse([
                'ok' => false,
                'error' => ClientConnectionLease::ERROR_KEY_IN_USE,
                'errors' => [ClientConnectionLease::ERROR_KEY_IN_USE],
            ], 409);
        }

        $request->attributes->set('sa_client_agent', $agent);

        return $next($request);
    }
}
