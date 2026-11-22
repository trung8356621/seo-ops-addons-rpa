<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Services\SocialAutomationSettingsService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

final class StaffSettingsController extends Controller
{
    public function __construct(
        private readonly SocialAutomationSettingsService $settings,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installation,
    ) {}

    public function show(): JsonResponse
    {
        $this->access->assertCanAccess();

        return response()->json([
            'ok' => true,
            'data' => $this->settings->getSettings($this->installation->resolveInstallationId()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->access->assertCanAccessAdminPages();

        $result = $this->settings->updateSettings(
            $this->installation->resolveInstallationId(),
            $request->all(),
            $request->user()?->id ? (int) $request->user()->id : null,
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'] ?? 'Validation failed.',
                'errors' => $result['errors'] ?? ['settings' => ['Update failed.']],
                'error_codes' => $result['error_codes'] ?? ['validation_failed'],
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json(['ok' => true, 'data' => $result['data']]);
    }
}
