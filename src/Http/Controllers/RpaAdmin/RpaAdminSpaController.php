<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the same-origin RPA Admin React SPA shell (index.html) for five UI paths.
 * Auth is enforced by Staff Session middleware on the routes (not here).
 */
final class RpaAdminSpaController extends Controller
{
    public const INDEX_RELATIVE = 'build-rpa-web/index.html';

    public const UNAVAILABLE_BODY = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Unavailable</title></head><body><p>RPA Admin UI is temporarily unavailable.</p></body></html>';

    public function __invoke(): Response
    {
        return self::responseForIndex(public_path(self::INDEX_RELATIVE));
    }

    /**
     * Build a controlled HTML response for the SPA shell.
     * Missing/unreadable builds return 503 without exposing filesystem details.
     */
    public static function responseForIndex(string $indexPath): Response
    {
        if (! is_file($indexPath) || ! is_readable($indexPath)) {
            return new Response(self::UNAVAILABLE_BODY, 503, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $html = @file_get_contents($indexPath);
        if ($html === false || $html === '') {
            return new Response(self::UNAVAILABLE_BODY, 503, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
