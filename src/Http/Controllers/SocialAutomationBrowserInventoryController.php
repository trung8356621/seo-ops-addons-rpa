<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationInstallationResolver;

/**
 * Staff Session Browser-first inventory (read-only).
 * Auth: SEO-OPS staff session middleware group — never Client API Key / RPA Admin Bearer.
 */
final class SocialAutomationBrowserInventoryController extends Controller
{
    public function __construct(
        private readonly SessionSyncService $sessions,
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationInstallationResolver $installations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();

        $installationId = $this->installations->resolveInstallationId();
        $payload = $this->sessions->staffBrowserInventory($installationId);

        return new JsonResponse($payload);
    }
}
