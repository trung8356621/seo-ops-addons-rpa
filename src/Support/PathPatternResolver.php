<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Omnichannel\Addons\SocialAutomation\Models\SaSite;

final class PathPatternResolver
{
    /**
     * Auto-detect placeholders like {group_id}, {post_id} from URL pattern.
     * Path Inputs are always string and required by default.
     *
     * @return list<array{key: string, type: string, required: bool}>
     */
    public static function parseInputs(string $urlPattern): array
    {
        $pattern = trim($urlPattern);
        if ($pattern === '') {
            return [];
        }

        if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $pattern, $matches) === false) {
            return [];
        }

        $uniqueKeys = array_values(array_unique($matches[1] ?? []));
        $inputs = [];

        foreach ($uniqueKeys as $key) {
            $inputs[] = [
                'key' => (string) $key,
                'type' => 'string',
                'required' => true,
            ];
        }

        return $inputs;
    }

    /**
     * Test & resolve a URL from Base URL + Pattern + Sample Input Values.
     * Supports subdirectories in Base URL (e.g. https://example.test/blog/).
     *
     * @param array<string, mixed> $inputValues
     * @return array{url: string, missing: list<string>, valid: bool, error: ?string}
     */
    public static function resolveUrl(string $baseUrl, string $urlPattern, array $inputValues = []): array
    {
        $normalizedBase = rtrim(trim($baseUrl), '/');
        $pattern = trim($urlPattern);

        if ($pattern === '') {
            $pattern = '/';
        }

        if (! str_starts_with($pattern, '/')) {
            $pattern = '/'.$pattern;
        }

        $expectedInputs = self::parseInputs($pattern);
        $missing = [];
        $resolvedPath = $pattern;

        if ($normalizedBase === '') {
            $missing[] = 'base_url';
        }

        foreach ($expectedInputs as $input) {
            $k = $input['key'];
            $val = isset($inputValues[$k]) ? trim((string) $inputValues[$k]) : '';

            if ($val === '') {
                $missing[] = $k;
            } else {
                // Encode URL path segment properly
                $encoded = rawurlencode($val);
                $resolvedPath = str_replace('{'.$k.'}', $encoded, $resolvedPath);
            }
        }

        $fullUrl = $normalizedBase.($resolvedPath === '/' ? '' : $resolvedPath);
        if ($fullUrl === '') {
            $fullUrl = $normalizedBase !== '' ? $normalizedBase : '/';
        }

        $isValid = $missing === [];
        $error = null;
        if (! $isValid) {
            if (in_array('base_url', $missing, true)) {
                $error = 'Base URL không được để trống.';
            } else {
                $error = 'Chưa điền đủ tham số: '.implode(', ', $missing);
            }
        }

        return [
            'url' => $fullUrl,
            'missing' => $missing,
            'valid' => $isValid,
            'error' => $error,
        ];
    }

    /**
     * Resolve full URL for a site using the unified Base URL contract.
     *
     * - `base_url` is canonical for Fixed and Dynamic Sites (preview + production).
     * - Dynamic mode may still accept an explicit one-shot `$runtimeBaseUrl` override.
     * - `test_base_url` is never used as a permanent runtime/preview fallback.
     * - `$isTestExecution` retained for call-site compatibility; it no longer changes URL source.
     *
     * @param SaSite|array<string, mixed> $site
     * @param array<string, mixed> $inputValues
     * @return array{url: string, missing: list<string>, valid: bool, error: ?string}
     */
    public static function resolveSiteUrl(
        SaSite|array $site,
        string $urlPattern,
        array $inputValues = [],
        ?string $runtimeBaseUrl = null,
        bool $isTestExecution = false
    ): array {
        unset($isTestExecution); // signature retained; no longer selects test_base_url

        $mode = $site instanceof SaSite
            ? ($site->base_url_mode ?? SaSite::MODE_FIXED)
            : ($site['base_url_mode'] ?? SaSite::MODE_FIXED);

        $siteName = $site instanceof SaSite
            ? (string) $site->name
            : (string) ($site['name'] ?? 'Site');

        $canonicalBaseUrl = $site instanceof SaSite
            ? (string) ($site->base_url ?? '')
            : (string) ($site['base_url'] ?? '');
        $canonicalBaseUrl = trim($canonicalBaseUrl);

        $runtime = trim((string) ($runtimeBaseUrl ?? ''));

        if ($mode === SaSite::MODE_DYNAMIC) {
            // Explicit runtime override wins; otherwise use configured base_url.
            $effectiveBaseUrl = $runtime !== '' ? $runtime : $canonicalBaseUrl;
            if ($effectiveBaseUrl === '') {
                return [
                    'url' => '',
                    'missing' => ['base_url'],
                    'valid' => false,
                    'error' => "Site [{$siteName}] chưa được cấu hình Base URL.",
                ];
            }

            return self::finalizeResolved(self::resolveUrl($effectiveBaseUrl, $urlPattern, $inputValues));
        }

        // Fixed mode: always use configured base_url (runtime override ignored).
        if ($canonicalBaseUrl === '') {
            return [
                'url' => '',
                'missing' => ['base_url'],
                'valid' => false,
                'error' => "Site [{$siteName}] chưa được cấu hình Base URL cố định.",
            ];
        }

        return self::finalizeResolved(self::resolveUrl($canonicalBaseUrl, $urlPattern, $inputValues));
    }

    /**
     * @param  array{url: string, missing: list<string>, valid: bool, error: ?string}  $resolved
     * @return array{url: string, missing: list<string>, valid: bool, error: ?string}
     */
    private static function finalizeResolved(array $resolved): array
    {
        if (! $resolved['valid'] && ! empty($resolved['missing']) && $resolved['error'] === null) {
            $resolved['error'] = 'Chưa điền đủ tham số: '.implode(', ', $resolved['missing']);
        }

        return $resolved;
    }
}
