<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Typed Script variable view sourced from an immutable published Script release manifest.
 */
final class CampaignScriptVariableSchema
{
    /** @var list<string> */
    public const SHORTCODE_SUPPORTED_TYPES = [
        'string', 'text', 'integer', 'number', 'boolean', 'url', 'enum',
        'file', 'files',
    ];

    /**
     * Types that shortcode mapping must never target.
     * file/files are supported as Client-local absolute path refs (not free text).
     *
     * @var list<string>
     */
    public const SHORTCODE_UNSUPPORTED_TYPES = [];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ok: bool, variables?: array<string, array<string, mixed>>, errors?: list<string>}
     */
    public function fromManifest(array $manifest): array
    {
        $raw = $manifest['variables'] ?? [];
        if (! is_array($raw)) {
            return ['ok' => false, 'errors' => ['script_variables_invalid']];
        }

        $variables = [];
        $errors = [];
        foreach ($raw as $i => $var) {
            if (is_string($var)) {
                $key = trim($var);
                if ($key === '' || isset($variables[$key])) {
                    $errors[] = 'script_variable_key_invalid:'.$i;
                    continue;
                }
                $variables[$key] = $this->normalize([
                    'key' => $key,
                    'type' => 'string',
                    'required' => true,
                ]);
                continue;
            }
            if (! is_array($var)) {
                $errors[] = 'script_variable_invalid:'.$i;
                continue;
            }
            $key = trim((string) ($var['key'] ?? $var['name'] ?? ''));
            if ($key === '' || isset($variables[$key])) {
                $errors[] = 'script_variable_key_invalid:'.$i;
                continue;
            }
            $variables[$key] = $this->normalize(array_merge($var, ['key' => $key]));
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'variables' => $variables];
    }

    /**
     * Sanitized Staff/API projection of Script variables from an immutable release manifest.
     * Distinguishes genuinely empty variables from invalid metadata.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{
     *   status: 'ok'|'empty'|'invalid',
     *   variables: list<array<string, mixed>>,
     *   errors: list<string>
     * }
     */
    public function projectStaffList(array $manifest): array
    {
        if (! array_key_exists('variables', $manifest) || $manifest['variables'] === null) {
            return [
                'status' => 'invalid',
                'variables' => [],
                'errors' => ['script_variables_missing'],
            ];
        }

        $parsed = $this->fromManifest($manifest);
        if (! ($parsed['ok'] ?? false)) {
            return [
                'status' => 'invalid',
                'variables' => [],
                'errors' => $parsed['errors'] ?? ['script_variables_invalid'],
            ];
        }

        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = $parsed['variables'] ?? [];
        $list = [];
        foreach ($byKey as $key => $var) {
            $list[] = $this->toStaffItem($var, (string) $key);
        }

        return [
            'status' => $list === [] ? 'empty' : 'ok',
            'variables' => $list,
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array<string, mixed>
     */
    public function toStaffItem(array $var, ?string $key = null): array
    {
        $k = $key ?? (string) ($var['key'] ?? '');
        $type = (string) ($var['type'] ?? 'string');
        $item = [
            'key' => $k,
            'name' => $k,
            'type' => $type,
            'required' => (bool) ($var['required'] ?? true),
            'allow_multiple' => (bool) ($var['allow_multiple'] ?? false),
            'shortcode_compatible' => $this->isShortcodeSupported($type)
                && ! in_array($type, self::SHORTCODE_UNSUPPORTED_TYPES, true),
        ];
        foreach (['min', 'max', 'minLength', 'maxLength', 'pattern'] as $constraint) {
            if (array_key_exists($constraint, $var) && $var[$constraint] !== null && $var[$constraint] !== '') {
                $item[$constraint] = $var[$constraint];
            }
        }
        if (is_array($var['enum'] ?? null) && $var['enum'] !== []) {
            $item['enum'] = array_values($var['enum']);
            $item['allowed_values'] = array_values($var['enum']);
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{
     *   key: string,
     *   id: string|null,
     *   type: string,
     *   required: bool,
     *   allow_multiple: bool,
     *   min: int|float|null,
     *   max: int|float|null,
     *   minLength: int|null,
     *   maxLength: int|null,
     *   pattern: string|null,
     *   enum: list<string|int|float|bool>|null
     * }
     */
    private function normalize(array $var): array
    {
        $type = strtolower(trim((string) ($var['type'] ?? 'string')));
        if ($type === 'text') {
            $type = 'string';
        }

        $enum = null;
        $rawEnum = $var['enum'] ?? $var['allowed_values'] ?? null;
        if (is_array($rawEnum) && $rawEnum !== []) {
            $enum = array_values($rawEnum);
            if ($type === 'select' || $type === 'string' && $enum !== null) {
                // keep type; enum constraint applies
            }
            if ($type === 'select') {
                $type = 'enum';
            }
        }

        $required = array_key_exists('required', $var)
            ? (bool) $var['required']
            : true;

        return [
            'key' => (string) $var['key'],
            'id' => isset($var['id']) ? trim((string) $var['id']) : null,
            'type' => $type,
            'required' => $required,
            'allow_multiple' => (bool) ($var['allow_multiple'] ?? false),
            'min' => $this->numOrNull($var['min'] ?? $var['minimum'] ?? null),
            'max' => $this->numOrNull($var['max'] ?? $var['maximum'] ?? null),
            'minLength' => $this->intOrNull($var['minLength'] ?? $var['min_length'] ?? null),
            'maxLength' => $this->intOrNull($var['maxLength'] ?? $var['max_length'] ?? null),
            'pattern' => isset($var['pattern']) ? (string) $var['pattern'] : null,
            'enum' => $enum,
        ];
    }

    public function isShortcodeSupported(string $type): bool
    {
        return in_array($type, self::SHORTCODE_SUPPORTED_TYPES, true)
            || $type === 'text';
    }

    public function isShortcodeUnsupported(string $type): bool
    {
        return in_array($type, self::SHORTCODE_UNSUPPORTED_TYPES, true)
            || ! $this->isShortcodeSupported($type);
    }

    /**
     * @param  array<string, array<string, mixed>>  $variables
     * @return list<string>
     */
    public function requiredKeys(array $variables): array
    {
        $keys = [];
        foreach ($variables as $key => $var) {
            if ((bool) ($var['required'] ?? false)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    private function numOrNull(mixed $v): int|float|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return str_contains((string) $v, '.') ? (float) $v : (int) $v;
        }

        return null;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }
}
