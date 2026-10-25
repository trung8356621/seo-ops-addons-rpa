<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Resolves Social Automation frontend assets from a dedicated Vite build boundary.
 *
 * Dev:  public/hot-social-automation -> Social Automation Vite (port 5175)
 * Prod: public/build-social-automation/manifest.json
 *
 * Does NOT mutate Core Vite (public/hot, public/build).
 */
final class SocialAutomationVite
{
    public const BUILD_DIRECTORY = 'build-social-automation';

    public const HOT_FILE = 'hot-social-automation';

    /** @var list<string> */
    public const ENTRYPOINTS = [
        'resources/js/action-tags/index.tsx',
        'resources/css/action-tags.css',
    ];

    public function hotFilePath(): string
    {
        return $this->resolvePublicPath(self::HOT_FILE);
    }

    public function buildDirectory(): string
    {
        return self::BUILD_DIRECTORY;
    }

    public function manifestPath(): string
    {
        return $this->resolvePublicPath(self::BUILD_DIRECTORY.DIRECTORY_SEPARATOR.'manifest.json');
    }

    public function resolvePublicPath(string $relative = ''): string
    {
        if (function_exists('public_path')) {
            try {
                $app = app();
                if ($app instanceof \Illuminate\Foundation\Application || method_exists($app, 'publicPath')) {
                    return public_path($relative);
                }
            } catch (\Throwable) {
                // Fallback below
            }
        }

        $candidates = [
            'D:/work/omnichannel-client/public',
            dirname(__DIR__, 4).'/omnichannel-client/public',
            dirname(__DIR__, 2).'/public',
        ];

        foreach ($candidates as $cand) {
            if (is_dir($cand)) {
                return rtrim($cand, '/\\').($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative, '/\\') : '');
            }
        }

        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.($relative !== '' ? DIRECTORY_SEPARATOR.ltrim($relative, '/\\') : '');
    }

    /**
     * Check if dev server is currently running.
     * Checks file presence AND verifies server response with a quick timeout (prevents stale hot file).
     */
    public function isDevServerRunning(): bool
    {
        if (! is_file($this->hotFilePath())) {
            return false;
        }

        try {
            $base = $this->hotBaseUrl();
            if ($base === '') {
                return false;
            }

            $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);
            $headers = @get_headers($base.'/@vite/client', false, $ctx);
            if ($headers === false || ! str_contains((string) ($headers[0] ?? ''), '200')) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * HTML tags for Social Automation JS/CSS (dev HMR or production hashed assets).
     */
    public function tags(): HtmlString
    {
        try {
            if ($this->isDevServerRunning()) {
                return new HtmlString($this->devTags());
            }

            return new HtmlString($this->productionTags());
        } catch (\Throwable $e) {
            $this->safeReport($e);

            return new HtmlString('<!-- social automation vite assets unavailable: '.e($e->getMessage()).' -->');
        }
    }

    private function hotBaseUrl(): string
    {
        $raw = trim((string) file_get_contents($this->hotFilePath()));
        if ($raw === '') {
            throw new RuntimeException('Social Automation hot file is empty.');
        }

        return rtrim($raw, '/');
    }

    private function devTags(): string
    {
        $base = $this->hotBaseUrl();
        $tags = [];

        // React Refresh preamble (Vite + @vitejs/plugin-react)
        $tags[] = '<script type="module">'.
            'import RefreshRuntime from '.json_encode($base.'/@react-refresh').';'.
            'RefreshRuntime.injectIntoGlobalHook(window);'.
            'window.$RefreshReg$ = () => {};'.
            'window.$RefreshSig$ = () => (type) => type;'.
            'window.__vite_plugin_react_preamble_installed__ = true;'.
            '</script>';

        $tags[] = '<script type="module" src="'.e($base.'/@vite/client').'"></script>';

        foreach (self::ENTRYPOINTS as $entry) {
            if (str_ends_with($entry, '.css')) {
                $tags[] = '<link rel="stylesheet" href="'.e($base.'/'.$entry).'">';
                continue;
            }
            $tags[] = '<script type="module" src="'.e($base.'/'.$entry).'"></script>';
        }

        return implode("\n", $tags);
    }

    private function productionTags(): string
    {
        $manifestPath = $this->manifestPath();
        if (! is_file($manifestPath)) {
            // Also check internal package resources/dist as fallback before failing
            $localManifest = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($localManifest)) {
                $manifestPath = $localManifest;
            } else {
                throw new RuntimeException('Social Automation manifest missing: '.$manifestPath);
            }
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Social Automation manifest is invalid JSON.');
        }

        $tags = [];
        $emittedCss = [];

        foreach (self::ENTRYPOINTS as $entry) {
            $chunk = $this->findManifestChunk($decoded, $entry);

            $file = (string) $chunk['file'];
            $url = $this->assetUrl(self::BUILD_DIRECTORY.'/'.$file);

            if (str_ends_with($file, '.css')) {
                if (! isset($emittedCss[$url])) {
                    $tags[] = '<link rel="stylesheet" href="'.e($url).'">';
                    $emittedCss[$url] = true;
                }
            } else {
                $tags[] = '<script type="module" src="'.e($url).'"></script>';
            }

            foreach (($chunk['css'] ?? []) as $cssFile) {
                $cssUrl = $this->assetUrl(self::BUILD_DIRECTORY.'/'.$cssFile);
                if (isset($emittedCss[$cssUrl])) {
                    continue;
                }
                $tags[] = '<link rel="stylesheet" href="'.e($cssUrl).'">';
                $emittedCss[$cssUrl] = true;
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Vite may emit junction-relative keys on Windows.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{file: string, css?: list<string>, src?: string}
     */
    private function findManifestChunk(array $manifest, string $entry): array
    {
        $direct = $manifest[$entry] ?? null;
        if (is_array($direct) && ! empty($direct['file'])) {
            /** @var array{file: string, css?: list<string>, src?: string} $direct */
            return $direct;
        }

        $normalizedEntry = str_replace('\\', '/', $entry);

        foreach ($manifest as $key => $chunk) {
            if (! is_array($chunk) || empty($chunk['file'])) {
                continue;
            }

            $candidates = [
                str_replace('\\', '/', (string) $key),
                str_replace('\\', '/', (string) ($chunk['src'] ?? '')),
            ];

            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if (
                    $candidate === $normalizedEntry
                    || str_ends_with($candidate, '/'.$normalizedEntry)
                ) {
                    /** @var array{file: string, css?: list<string>, src?: string} $chunk */
                    return $chunk;
                }
            }
        }

        throw new RuntimeException('Social Automation manifest entry missing: '.$entry);
    }

    private function assetUrl(string $path): string
    {
        if (function_exists('asset')) {
            try {
                $app = app();
                if ($app->bound('url')) {
                    return asset($path);
                }
            } catch (\Throwable) {
                // Fallback below
            }
        }

        return '/'.ltrim($path, '/');
    }

    private function safeReport(\Throwable $e): void
    {
        try {
            if (function_exists('report') && app()->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
                report($e);
            }
        } catch (\Throwable) {
            // Unbound container or unit test runner
        }
    }
}
