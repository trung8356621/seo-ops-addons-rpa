<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureRpaClientApiKey;
use Omnichannel\Addons\SocialAutomation\Models\SaClientApiKey;

final class RpaClientApiController extends Controller
{
    /**
     * Validate client API Key and return installation context.
     * Raw key and key_hash are never returned.
     */
    public function validateKey(Request $request): JsonResponse
    {
        $clientKey = $request->attributes->get(EnsureRpaClientApiKey::REQUEST_CLIENT_KEY);
        if (! $clientKey instanceof SaClientApiKey) {
            $clientKey = function_exists('app') && app()->bound(SaClientApiKey::class) ? app(SaClientApiKey::class) : null;
        }

        if (! $clientKey instanceof SaClientApiKey) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'unauthenticated',
            ], 401);
        }

        return new JsonResponse([
            'ok' => true,
            'client_api_key' => [
                'id' => (int) $clientKey->getAttribute('id'),
                'name' => (string) $clientKey->getAttribute('name'),
                'key_prefix' => (string) $clientKey->getAttribute('key_prefix'),
                'installation_id' => (string) $clientKey->getAttribute('installation_id'),
                'last_used_at' => $clientKey->last_used_at?->toIso8601String(),
                'created_at' => $clientKey->created_at?->toIso8601String(),
            ],
            'installation' => [
                'installation_id' => (string) $clientKey->getAttribute('installation_id'),
            ],
        ]);
    }
}
