<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protect OpenAPI / Swagger with SEO-OPS identity only.
 * Accepts Filament/web session (admin|owner) OR RPA Admin opaque Bearer.
 */
final class EnsureSocialAutomationDocsAccess
{
    public function __construct(
        private readonly SocialAutomationAccess $access,
        private readonly RpaAdminSessionService $rpaAdminSessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user instanceof User && $this->access->canAccessAdminPages($user)) {
            return $next($request);
        }

        $token = $this->bearer($request);
        if ($token !== null) {
            $resolved = $this->rpaAdminSessions->resolve($token);
            if (($resolved['ok'] ?? false) === true && ($resolved['user'] ?? null) instanceof User) {
                /** @var User $adminUser */
                $adminUser = $resolved['user'];
                if ($this->access->canAccessAdminPages($adminUser)) {
                    $request->attributes->set(RpaAdminSessionService::REQUEST_USER_KEY, $adminUser);

                    return $next($request);
                }
            }
        }

        if (
            $request->expectsJson()
            || $request->is('api/*')
            || $request->is('social-automation/api-docs')
            || str_ends_with($request->path(), '.json')
            || str_ends_with($request->path(), '.yaml')
        ) {
            return response()->json(
                ['ok' => false, 'errors' => ['unauthorized']],
                SocialAutomationApiStatus::fromResult(false, ['unauthorized'])
            );
        }

        return redirect()->guest(url('/login'));
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        $alt = trim((string) $request->header('X-Rpa-Admin-Token', ''));

        return $alt !== '' ? $alt : null;
    }
}
