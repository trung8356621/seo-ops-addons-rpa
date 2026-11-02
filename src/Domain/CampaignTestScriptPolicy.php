<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Fail-closed Test Mode script gate.
 *
 * Existing Script manifests use free-form step.action strings (CLICK_TARGET, CLICK_AND_TYPE, …).
 * There is NO reliable schema field that marks a step as "publish/submit to social network".
 * Therefore Test Mode requires explicit author opt-in markers and rejects known publish-like actions.
 * Generic clicks cannot be proven safe — limitation is reported to callers.
 */
final class CampaignTestScriptPolicy
{
    /** @var list<string> */
    public const KNOWN_PUBLISH_ACTIONS = [
        'submit', 'publish', 'post', 'share', 'send', 'send_post', 'create_post',
        'click_submit', 'click_publish', 'click_post', 'confirm_publish',
        'submit_post', 'publish_post', 'post_submit',
    ];

    /** @var list<string> */
    public const SCREENSHOT_ACTIONS = [
        'screenshot', 'capture_screenshot', 'take_screenshot', 'screen_capture',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{
     *   ok: bool,
     *   errors?: list<string>,
     *   limitations?: list<string>,
     *   terminal_action?: string|null,
     *   stops_before_publish?: bool
     * }
     */
    public function assertCompatibleForTestMode(array $manifest): array
    {
        $limitations = [
            'script_actions_are_free_form',
            'publish_actions_cannot_be_reliably_inferred_from_generic_clicks',
            'client_did_not_publish_flags_are_not_trusted',
            'screenshot_does_not_prove_publication_or_non_publication',
        ];

        $explicit = $this->hasExplicitTestCompatibility($manifest);
        if (! $explicit) {
            return [
                'ok' => false,
                'errors' => ['script_not_test_compatible'],
                'limitations' => $limitations,
            ];
        }

        $stops = $this->declaresStopBeforePublish($manifest);
        $terminal = $this->resolveTerminalAction($manifest);
        $terminalIsScreenshot = $terminal !== null && in_array($terminal, self::SCREENSHOT_ACTIONS, true);

        if (! $stops && ! $terminalIsScreenshot) {
            return [
                'ok' => false,
                'errors' => ['script_test_missing_stop_before_publish'],
                'limitations' => $limitations,
                'terminal_action' => $terminal,
                'stops_before_publish' => false,
            ];
        }

        $publishHits = $this->findPublishLikeActions($manifest);
        if ($publishHits !== []) {
            return [
                'ok' => false,
                'errors' => array_map(
                    static fn (string $a): string => 'script_test_forbidden_action:'.$a,
                    $publishHits
                ),
                'limitations' => $limitations,
                'terminal_action' => $terminal,
                'stops_before_publish' => $stops,
            ];
        }

        if (! $terminalIsScreenshot && ! $this->hasScreenshotIntent($manifest)) {
            return [
                'ok' => false,
                'errors' => ['script_test_requires_screenshot_terminal'],
                'limitations' => $limitations,
                'terminal_action' => $terminal,
                'stops_before_publish' => $stops,
            ];
        }

        return [
            'ok' => true,
            'limitations' => $limitations,
            'terminal_action' => $terminal ?? 'screenshot',
            'stops_before_publish' => true,
        ];
    }

    /**
     * Never accept unverified client flags as proof that publish did not occur.
     *
     * @param  array<string, mixed>  $resultPayload
     * @return list<string>
     */
    public function rejectUntrustedNonPublishClaims(array $resultPayload): array
    {
        $errors = [];
        $suspectKeys = [
            'did_not_publish', 'no_publish', 'publish_skipped', 'publish_skipped_verified',
            'stopped_before_publish_verified', 'safe_non_publish', 'test_mode_proves_no_publish',
        ];
        foreach ($suspectKeys as $key) {
            if (array_key_exists($key, $resultPayload) && $resultPayload[$key]) {
                $errors[] = 'untrusted_non_publish_claim:'.$key;
            }
        }
        $meta = is_array($resultPayload['metadata'] ?? null) ? $resultPayload['metadata'] : [];
        foreach ($suspectKeys as $key) {
            if (array_key_exists($key, $meta) && $meta[$key]) {
                $errors[] = 'untrusted_non_publish_claim:metadata.'.$key;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function hasExplicitTestCompatibility(array $manifest): bool
    {
        if (filter_var($manifest['test_mode_compatible'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        if (filter_var($manifest['campaign_test_compatible'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        foreach (['campaign_run_modes', 'compatible_run_modes', 'run_modes'] as $field) {
            $modes = $manifest[$field] ?? null;
            if (! is_array($modes)) {
                continue;
            }
            foreach ($modes as $mode) {
                if (strtolower(trim((string) $mode)) === 'test') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function declaresStopBeforePublish(array $manifest): bool
    {
        if (filter_var($manifest['stops_before_publish'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $intent = strtolower(trim((string) ($manifest['terminal_intent'] ?? $manifest['last_intended_action'] ?? '')));

        return in_array($intent, ['screenshot', 'capture_screenshot', 'stop_before_publish'], true);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function hasScreenshotIntent(array $manifest): bool
    {
        $intent = strtolower(trim((string) ($manifest['terminal_intent'] ?? $manifest['last_intended_action'] ?? '')));
        if (in_array($intent, self::SCREENSHOT_ACTIONS, true) || $intent === 'stop_before_publish') {
            return true;
        }

        return $this->resolveTerminalAction($manifest) !== null
            && in_array((string) $this->resolveTerminalAction($manifest), self::SCREENSHOT_ACTIONS, true);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function resolveTerminalAction(array $manifest): ?string
    {
        $steps = $manifest['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            $declared = strtolower(trim((string) ($manifest['terminal_action'] ?? '')));

            return $declared !== '' ? $declared : null;
        }
        $last = $steps[array_key_last($steps)];
        if (! is_array($last)) {
            return null;
        }
        $action = strtolower(trim((string) ($last['action'] ?? $last['type'] ?? '')));

        return $action !== '' ? $action : null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function findPublishLikeActions(array $manifest): array
    {
        $hits = [];
        $steps = is_array($manifest['steps'] ?? null) ? $manifest['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $action = strtolower(trim((string) ($step['action'] ?? $step['type'] ?? '')));
            $name = strtolower(trim((string) ($step['name'] ?? '')));
            foreach (self::KNOWN_PUBLISH_ACTIONS as $forbidden) {
                if ($action === $forbidden || str_contains($action, $forbidden) || str_contains($name, $forbidden)) {
                    $hits[] = $action !== '' ? $action : $forbidden;
                }
            }
        }

        return array_values(array_unique($hits));
    }
}
