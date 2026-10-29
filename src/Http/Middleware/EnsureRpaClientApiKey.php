<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\RpaClientApiKeyValidationResult;
use Omnichannel\Addons\SocialAutomation\Domain\StaffClientEnrollmentBootstrap;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Services\RpaClientApiKeyService;
use Omnichannel\Addons\SocialAutomation\Services\StaffClientEnrollmentService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication middleware for RPA Client machines.
 * Authorization: Bearer <client_api_key>
 *
 * Strict installation scope validation.
 * Never leaks plaintext key into logs, exceptions or response body.
 *
 * Enrollment bootstrap Keys (staff-scoped, unsigned-to-DB) are accepted ONLY on
 * POST .../client/register so they can materialize into a real Client API Key.
 * Bootstrap alone never authorizes heartbeat, browser-sync, accounts, or auth pull.
 */
final class EnsureRpaClientApiKey
{
    public const REQUEST_CLIENT_KEY = 'sa_client_api_key';

    public const REQUEST_ENROLLMENT_BOOTSTRAP = 'sa_enrollment_bootstrap';

    public const REQUEST_ENROLLMENT_RAW_KEY = 'sa_enrollment_raw_key';

    public function __construct(
        private readonly RpaClientApiKeyService $keyService,
        private readonly ?StaffClientEnrollmentService $enrollment = null,
        private readonly ?SocialAutomationInstallationResolver $installationResolver = null,
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
        if ($result->valid && $result->key instanceof SaClientApiKey) {
            $request->attributes->set(self::REQUEST_CLIENT_KEY, $result->key);
            if (function_exists('app')) {
                try {
                    app()->instance(SaClientApiKey::class, $result->key);
                } catch (\Throwable) {
                }
            }

            return $next($request);
        }

        $enrollment = $this->enrollmentService();
        $parsed = $enrollment?->parseBootstrap($token);
        if (is_array($parsed)) {
            if (! $this->isClientRegisterRequest($request)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'enrollment_bootstrap_not_authorized',
                    'message' => 'Bootstrap Key authorizes Client enrollment/register only. Complete registration first.',
                ], 401);
            }

            $installationId = $this->installationResolver()?->resolveInstallationId() ?? (string) ($parsed['installation_id'] ?? '');
            $usableError = (new StaffClientEnrollmentBootstrap)->assertUsable($parsed, $installationId);
            if ($usableError !== null) {
                $status = $usableError === StaffClientEnrollmentBootstrap::ERROR_INSTALLATION_MISMATCH ? 403 : 401;

                return new JsonResponse([
                    'ok' => false,
                    'error' => $usableError,
                    'message' => 'Enrollment bootstrap Key is invalid or expired.',
                ], $status);
            }

            $request->attributes->set(self::REQUEST_ENROLLMENT_BOOTSTRAP, $parsed);
            $request->attributes->set(self::REQUEST_ENROLLMENT_RAW_KEY, $token);

            return $next($request);
        }

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

    private function isClientRegisterRequest(Request $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }
        $path = '/'.ltrim($request->path(), '/');

        return str_ends_with($path, '/client/register');
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

    private function enrollmentService(): ?StaffClientEnrollmentService
    {
        if ($this->enrollment instanceof StaffClientEnrollmentService) {
            return $this->enrollment;
        }
        if (function_exists('app')) {
            try {
                return app(StaffClientEnrollmentService::class);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function installationResolver(): ?SocialAutomationInstallationResolver
    {
        if ($this->installationResolver instanceof SocialAutomationInstallationResolver) {
            return $this->installationResolver;
        }
        if (function_exists('app')) {
            try {
                return app(SocialAutomationInstallationResolver::class);
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
