<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Validates content input against Script variable names.
 * Keeps placeholders; never expands or persists resolved secrets.
 */
final class CampaignInputSanitizer
{
    /** @var list<string> */
    public const ALLOWED_PLACEHOLDERS = [
        '{account.username}',
        '{account.password}',
        '{account.email}',
        '{account.domain}',
        '{account.site_url}',
    ];

    /** @var list<string> */
    private const SECRET_KEYS = [
        'password', 'passwd', 'secret', 'token', 'credential', 'api_key', 'authorization', 'cookie', 'cookies',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $scriptVariableNames  empty = accept any non-secret keys + placeholders
     * @return array{ok: bool, input?: array<string, mixed>, errors?: list<string>}
     */
    public function sanitize(array $input, array $scriptVariableNames = []): array
    {
        $errors = [];
        $clean = [];
        $allowed = array_fill_keys(array_map('strval', $scriptVariableNames), true);

        foreach ($input as $key => $value) {
            $k = (string) $key;
            if ($k === '' || str_starts_with($k, '_')) {
                $errors[] = 'input_key_invalid:'.$k;
                continue;
            }

            if ($this->isSecretKey($k) && ! $this->isAllowedPlaceholder($value)) {
                $errors[] = 'plaintext_secret_forbidden:'.$k;
                continue;
            }

            if ($allowed !== [] && ! isset($allowed[$k])) {
                $errors[] = 'unsupported_script_variable:'.$k;
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($this->looksLikeResolvedSecret($k, $trimmed)) {
                    $errors[] = 'resolved_secret_forbidden:'.$k;
                    continue;
                }
                $clean[$k] = $trimmed;
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$k] = $value;
                continue;
            }

            if (is_array($value)) {
                $nested = $this->sanitize($value, []);
                if (! ($nested['ok'] ?? false)) {
                    foreach ($nested['errors'] ?? [] as $err) {
                        $errors[] = $k.'.'.$err;
                    }
                    continue;
                }
                $clean[$k] = $nested['input'] ?? [];
                continue;
            }

            $errors[] = 'input_type_invalid:'.$k;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'input' => $clean];
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if ($lower === $secret || str_contains($lower, $secret)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedPlaceholder(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(trim($value), self::ALLOWED_PLACEHOLDERS, true);
    }

    private function looksLikeResolvedSecret(string $key, string $value): bool
    {
        if (! $this->isSecretKey($key)) {
            return false;
        }
        if ($this->isAllowedPlaceholder($value)) {
            return false;
        }

        // Any non-placeholder secret-looking field is rejected from persistence.
        return $value !== '';
    }
}
