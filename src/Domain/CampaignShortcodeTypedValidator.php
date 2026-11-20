<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Type-aware validation of Script input values.
 * Enforces ALL required Script variables (mapped or not) have values.
 */
final class CampaignShortcodeTypedValidator
{
    public function __construct(
        private readonly CampaignScriptVariableSchema $schema = new CampaignScriptVariableSchema,
    ) {}

    /**
     * @param  array<string, mixed>  $input  variable key => value
     * @param  array<string, array<string, mixed>>  $variables
     * @return array{ok: bool, input?: array<string, mixed>, errors?: list<string>}
     */
    public function validate(array $input, array $variables): array
    {
        $errors = [];
        $clean = [];

        foreach ($variables as $key => $var) {
            $required = (bool) ($var['required'] ?? false);
            $has = array_key_exists($key, $input);
            if (! $has) {
                if ($required) {
                    $errors[] = 'required_variable_missing:'.$key;
                }
                continue;
            }

            $raw = $input[$key];
            $typed = $this->coerceAndCheck($key, $raw, $var);
            if (! ($typed['ok'] ?? false)) {
                foreach ($typed['errors'] ?? ['variable_invalid:'.$key] as $err) {
                    $errors[] = $err;
                }
                continue;
            }
            $clean[$key] = $typed['value'];
        }

        // Reject unknown keys not in schema
        foreach ($input as $key => $_) {
            if (! isset($variables[(string) $key])) {
                $errors[] = 'unsupported_script_variable:'.$key;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors))];
        }

        if ($clean === [] && $this->schema->requiredKeys($variables) !== []) {
            return ['ok' => false, 'errors' => ['content_input_empty']];
        }

        return ['ok' => true, 'input' => $clean];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: mixed, errors?: list<string>}
     */
    private function coerceAndCheck(string $key, mixed $raw, array $var): array
    {
        $type = (string) ($var['type'] ?? 'string');

        if (in_array($type, CampaignScriptVariableSchema::SHORTCODE_UNSUPPORTED_TYPES, true)
            || ! $this->schema->isShortcodeSupported($type)) {
            return ['ok' => false, 'errors' => ['unsupported_variable_type:'.$key.':'.$type]];
        }

        return match ($type) {
            'string', 'text' => $this->asString($key, $raw, $var),
            'url' => $this->asUrl($key, $raw, $var),
            'integer' => $this->asInteger($key, $raw, $var),
            'number' => $this->asNumber($key, $raw, $var),
            'boolean' => $this->asBoolean($key, $raw, $var),
            'enum' => $this->asEnum($key, $raw, $var),
            default => ['ok' => false, 'errors' => ['unsupported_variable_type:'.$key.':'.$type]],
        };
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: string, errors?: list<string>}
     */
    private function asString(string $key, mixed $raw, array $var): array
    {
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return ['ok' => false, 'errors' => ['variable_type_invalid:'.$key]];
        }
        $value = is_string($raw) ? $raw : (string) $raw;
        // Preserve inner content; only reject if required and empty after trim ends
        if ((bool) ($var['required'] ?? false) && trim($value) === '') {
            return ['ok' => false, 'errors' => ['required_variable_missing:'.$key]];
        }
        $len = mb_strlen($value);
        if (isset($var['minLength']) && is_int($var['minLength']) && $len < $var['minLength']) {
            return ['ok' => false, 'errors' => ['variable_min_length:'.$key]];
        }
        if (isset($var['maxLength']) && is_int($var['maxLength']) && $len > $var['maxLength']) {
            return ['ok' => false, 'errors' => ['variable_max_length:'.$key]];
        }
        $pattern = $var['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $ok = @preg_match('/'.$pattern.'/u', $value);
            if ($ok !== 1) {
                return ['ok' => false, 'errors' => ['variable_pattern:'.$key]];
            }
        }
        if (is_array($var['enum'] ?? null) && $var['enum'] !== [] && ! in_array($value, $var['enum'], true)) {
            return ['ok' => false, 'errors' => ['variable_enum_invalid:'.$key]];
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: string, errors?: list<string>}
     */
    private function asUrl(string $key, mixed $raw, array $var): array
    {
        $as = $this->asString($key, $raw, $var);
        if (! ($as['ok'] ?? false)) {
            return $as;
        }
        $value = trim((string) $as['value']);
        if ($value === '' && ! ($var['required'] ?? false)) {
            return ['ok' => true, 'value' => $value];
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'errors' => ['variable_url_invalid:'.$key]];
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: int, errors?: list<string>}
     */
    private function asInteger(string $key, mixed $raw, array $var): array
    {
        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            $raw = (int) trim($raw);
        }
        if (! is_int($raw)) {
            return ['ok' => false, 'errors' => ['variable_type_invalid:'.$key]];
        }
        if (isset($var['min']) && is_numeric($var['min']) && $raw < $var['min']) {
            return ['ok' => false, 'errors' => ['variable_min:'.$key]];
        }
        if (isset($var['max']) && is_numeric($var['max']) && $raw > $var['max']) {
            return ['ok' => false, 'errors' => ['variable_max:'.$key]];
        }

        return ['ok' => true, 'value' => $raw];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: float|int, errors?: list<string>}
     */
    private function asNumber(string $key, mixed $raw, array $var): array
    {
        if (is_string($raw) && is_numeric(trim($raw))) {
            $raw = str_contains(trim($raw), '.') ? (float) trim($raw) : (int) trim($raw);
        }
        if (! is_int($raw) && ! is_float($raw)) {
            return ['ok' => false, 'errors' => ['variable_type_invalid:'.$key]];
        }
        if (isset($var['min']) && is_numeric($var['min']) && $raw < $var['min']) {
            return ['ok' => false, 'errors' => ['variable_min:'.$key]];
        }
        if (isset($var['max']) && is_numeric($var['max']) && $raw > $var['max']) {
            return ['ok' => false, 'errors' => ['variable_max:'.$key]];
        }

        return ['ok' => true, 'value' => $raw];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: bool, errors?: list<string>}
     */
    private function asBoolean(string $key, mixed $raw, array $var): array
    {
        unset($var);
        if (is_bool($raw)) {
            return ['ok' => true, 'value' => $raw];
        }
        if (is_string($raw)) {
            $t = strtolower(trim($raw));
            if (in_array($t, ['true', '1', 'yes'], true)) {
                return ['ok' => true, 'value' => true];
            }
            if (in_array($t, ['false', '0', 'no'], true)) {
                return ['ok' => true, 'value' => false];
            }
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return ['ok' => true, 'value' => $raw === 1];
        }

        return ['ok' => false, 'errors' => ['variable_type_invalid:'.$key]];
    }

    /**
     * @param  array<string, mixed>  $var
     * @return array{ok: bool, value?: mixed, errors?: list<string>}
     */
    private function asEnum(string $key, mixed $raw, array $var): array
    {
        $enum = $var['enum'] ?? null;
        if (! is_array($enum) || $enum === []) {
            return ['ok' => false, 'errors' => ['shortcode_enum_values_required:'.$key]];
        }
        foreach ($enum as $allowed) {
            if ($raw === $allowed || (is_string($raw) && is_scalar($allowed) && (string) $raw === (string) $allowed)) {
                return ['ok' => true, 'value' => $allowed];
            }
        }

        return ['ok' => false, 'errors' => ['variable_enum_invalid:'.$key]];
    }
}
