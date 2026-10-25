<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

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
     *
     * @param array<string, mixed> $inputValues
     * @return array{url: string, missing: list<string>, valid: bool}
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

        return [
            'url' => $fullUrl,
            'missing' => $missing,
            'valid' => $missing === [],
        ];
    }
}
