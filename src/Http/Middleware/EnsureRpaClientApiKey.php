<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication middleware for RPA Client machines.
 * Authorization: Bearer <client_api_key>
 *
 * Strict installation scope validation.
 * Never leaks plaintext key into logs, exceptions or response body.
 */
final class EnsureRpaClientApiKey
{
    public const REQUEST_CLIENT_KEY = 'sa_client_api_key';

    public function __construct(
        private readonly RpaClientApiKeyService $keyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Missing or invalid Authorization header.',
            ], 401);
        }

        $result = $this->keyService->validate($token);
        if (! $result->valid || ! $result->key instanceof SaClientApiKey) {
            $status = match ($result->errorCode) {
                RpaClientApiKeyValidationResult::ERROR_KEY_REVOKED => 401,
                RpaClientApiKeyValidationResult::ERROR_INSTALLATION_MISMATCH => 403,
                default => 401,
            };

            return new JsonResponse([
                'ok' => false,
                'error' => $result->errorCode ?? 'invalid_api_key',
                'message' => $result->errorMessage ?? 'Authentication failed.',
            ], $status);
        }

        // Attach resolved key to request and service container
        $request->attributes->set(self::REQUEST_CLIENT_KEY, $result->key);
        if (function_exists('app')) {
            try {
                app()->instance(SaClientApiKey::class, $result->key);
            } catch (\Throwable) {
            }
        }

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        $token = trim((string) $matches[1]);

        return $token !== '' ? $token : null;
    }
}
