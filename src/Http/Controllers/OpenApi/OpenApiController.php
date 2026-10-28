<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\OpenApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves machine-readable OpenAPI 3.x and Swagger UI.
 * Access is gated by EnsureSocialAutomationDocsAccess (SEO-OPS session or RPA Admin token).
 */
final class OpenApiController extends Controller
{
    public function ui(Request $request): Response
    {
        $specUrl = url('/api/v1/social-automation/openapi.yaml');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Social Automation API — Swagger UI</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
  <style>
    body { margin: 0; background: #fafafa; }
    .topbar { display: none; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: {$this->jsString($specUrl)},
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
      layout: 'StandaloneLayout',
      persistAuthorization: true,
      tryItOutEnabled: true,
      requestInterceptor: (req) => {
        // Browser session cookie auth for docs; Bearer schemes set via Authorize.
        req.credentials = 'same-origin';
        return req;
      }
    });
  </script>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function yaml(): SymfonyResponse
    {
        $path = $this->specPath();
        if (! is_file($path)) {
            return response()->json(['ok' => false, 'errors' => ['openapi_missing']], 503);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function json(): JsonResponse|SymfonyResponse
    {
        $path = $this->specPath();
        if (! is_file($path)) {
            return response()->json(['ok' => false, 'errors' => ['openapi_missing']], 503);
        }

        $raw = (string) file_get_contents($path);
        if (class_exists(Yaml::class)) {
            $parsed = Yaml::parse($raw);
            if (! is_array($parsed)) {
                return response()->json(['ok' => false, 'errors' => ['openapi_invalid']], 503);
            }

            return response()->json($parsed, 200, [
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        // Symfony Yaml ships with Laravel; fallback keeps YAML body typed as JSON error if missing.
        return response()->json(['ok' => false, 'errors' => ['yaml_parser_unavailable']], 503);
    }

    private function specPath(): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'openapi'.DIRECTORY_SEPARATOR.'openapi.yaml';
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
