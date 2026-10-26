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
     * Resolve full URL for a site taking into account Fixed vs Dynamic Base URL mode.
     *
     * @param SaSite|array<string, mixed> $site
     * @param string $urlPattern
     * @param array<string, mixed> $inputValues
     * @param string|null $runtimeBaseUrl Base URL provided at runtime for dynamic sites
     * @param bool $isTestExecution When true, allows using test_base_url for preview/test
     * @return array{url: string, missing: list<string>, valid: bool, error: ?string}
     */
    public static function resolveSiteUrl(
        SaSite|array $site,
        string $urlPattern,
        array $inputValues = [],
        ?string $runtimeBaseUrl = null,
        bool $isTestExecution = false
    ): array {
        $mode = $site instanceof SaSite
            ? ($site->base_url_mode ?? SaSite::MODE_FIXED)
            : ($site['base_url_mode'] ?? SaSite::MODE_FIXED);

        $siteName = $site instanceof SaSite
            ? $site->name
            : ($site['name'] ?? 'Site');

        $testBaseUrl = $site instanceof SaSite
            ? $site->test_base_url
            : ($site['test_base_url'] ?? null);

        $fixedBaseUrl = $site instanceof SaSite
            ? $site->base_url
            : ($site['base_url'] ?? null);

        if ($mode === SaSite::MODE_DYNAMIC) {
            $effectiveBaseUrl = trim((string) ($runtimeBaseUrl ?? ''));

            // If this is an explicit test execution and runtime base URL is empty, fallback to test_base_url
            if ($isTestExecution && $effectiveBaseUrl === '') {
                $effectiveBaseUrl = trim((string) ($testBaseUrl ?? ''));
            }

            // At runtime / production, Test Base URL MUST NEVER be used as fallback!
            if ($effectiveBaseUrl === '') {
                $errorMsg = $isTestExecution
                    ? "Dynamic Base URL hoặc Test Base URL chưa được cung cấp cho Site [{$siteName}]."
                    : "Dynamic Base URL bắt buộc phải được cung cấp khi chạy Site [{$siteName}]. Không được dùng Test Base URL trong production.";

                return [
                    'url' => '',
                    'missing' => ['dynamic_base_url'],
                    'valid' => false,
                    'error' => $errorMsg,
                ];
            }

            $resolved = self::resolveUrl($effectiveBaseUrl, $urlPattern, $inputValues);
            if (! $resolved['valid'] && ! empty($resolved['missing']) && $resolved['error'] === null) {
                $resolved['error'] = 'Chưa điền đủ tham số: '.implode(', ', $resolved['missing']);
            }

            return $resolved;
        }

        // Fixed mode
        $fixed = trim((string) ($fixedBaseUrl ?? ''));
        if ($fixed === '') {
            return [
                'url' => '',
                'missing' => ['base_url'],
                'valid' => false,
                'error' => "Site [{$siteName}] chưa được cấu hình Base URL cố định.",
            ];
        }

        $resolved = self::resolveUrl($fixed, $urlPattern, $inputValues);
        if (! $resolved['valid'] && ! empty($resolved['missing']) && $resolved['error'] === null) {
            $resolved['error'] = 'Chưa điền đủ tham số: '.implode(', ', $resolved['missing']);
        }

        return $resolved;
    }
}
