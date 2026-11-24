<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Enums\JobStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Deterministic, account-scoped VI/EN variant priority resolver backed by completed job history.
 */
final class VariantPreferenceService
{
    /**
     * Extract the reliably identified pinned variant that succeeded from a terminal job result payload.
     *
     * @param  array<string, mixed>  $resultPayload
     * @param  list<array<string, mixed>>  $pinnedVariants
     * @param  list<array<string, mixed>>  $artifactRefs
     * @return array{variant_id: string, language_label: string, package_key: string, release_id: int}|null
     */
    public function extractSucceededVariant(
        array $resultPayload,
        array $pinnedVariants,
        array $artifactRefs = [],
    ): ?array {
        if ($pinnedVariants === []) {
            return null;
        }

        $output = is_array($resultPayload['output'] ?? null)
            ? $resultPayload['output']
            : (is_array($resultPayload['result'] ?? null) ? $resultPayload['result'] : []);

        // 1. Explicit selected/executed/succeeded variant object
        foreach ([
            $resultPayload['succeeded_variant'] ?? null,
            $resultPayload['selected_variant'] ?? null,
            $resultPayload['executed_variant'] ?? null,
            $output['succeeded_variant'] ?? null,
            $output['selected_variant'] ?? null,
            $output['executed_variant'] ?? null,
        ] as $candidate) {
            if (is_array($candidate)) {
                $matched = $this->matchPinnedVariant($candidate, $pinnedVariants);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        // 2. variant_attempts array (find the entry that succeeded)
        $attempts = $output['variant_attempts'] ?? $resultPayload['variant_attempts'] ?? null;
        if (is_array($attempts)) {
            foreach ($attempts as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $st = strtolower(trim((string) ($entry['status'] ?? '')));
                if (! in_array($st, ['succeeded', 'success'], true)) {
                    continue;
                }
                $matched = $this->matchPinnedVariant($entry, $pinnedVariants);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        // 3. Top-level or output variant_id
        foreach ([$resultPayload, $output] as $src) {
            if (isset($src['variant_id']) && trim((string) $src['variant_id']) !== '') {
                $matched = $this->matchPinnedVariant($src, $pinnedVariants);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        // 4. Final success screenshot artifact metadata
        foreach ($artifactRefs as $ref) {
            if (! is_array($ref) || ($ref['kind'] ?? null) !== 'screenshot') {
                continue;
            }
            $meta = is_array($ref['metadata'] ?? null) ? $ref['metadata'] : [];
            $outcome = strtolower(trim((string) ($meta['outcome'] ?? '')));
            $role = strtolower(trim((string) ($meta['screenshot_role'] ?? '')));
            if ($outcome === 'success' || $role === 'final_success') {
                $matched = $this->matchPinnedVariant($meta, $pinnedVariants);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the latest learned preferred variant for (installation, account, action_tag_key, variant_group_id).
     *
     * @return array{
     *     preferred_variant_id: string,
     *     preferred_language_label: string,
     *     preferred_release_id: int,
     *     package_key: string,
     *     preference_source_job_uuid: string,
     *     preference_updated_at: string|null
     * }|null
     */
    public function resolveForAccount(
        string $installationId,
        ?int $accountId,
        string $actionTagKey,
        ?string $variantGroupUuid,
    ): ?array {
        if ($accountId === null || $accountId <= 0 || $variantGroupUuid === null || trim($variantGroupUuid) === '' || trim($actionTagKey) === '') {
            return null;
        }
        if (! SocialAutomationSchema::jobsReady()) {
            return null;
        }

        $jobs = SaJob::query()
            ->where('account_id', $accountId)
            ->where('status', JobStatus::Completed->value)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'job_uuid', 'account_id', 'payload', 'result', 'finished_at']);

        foreach ($jobs as $job) {
            $payload = is_array($job->payload) ? $job->payload : [];
            if ((string) ($payload['installation_id'] ?? '') !== $installationId) {
                continue;
            }
            if (strcasecmp((string) ($payload['action_tag_key'] ?? ''), $actionTagKey) !== 0) {
                continue;
            }
            if ((string) ($payload['variant_group_id'] ?? '') !== $variantGroupUuid) {
                continue;
            }

            $pinned = is_array($payload['script_variants'] ?? null) ? $payload['script_variants'] : [];
            $result = is_array($job->result) ? $job->result : [];
            $succeeded = is_array($result['succeeded_variant'] ?? null)
                ? $this->matchPinnedVariant($result['succeeded_variant'], $pinned)
                : $this->extractSucceededVariant($result, $pinned, is_array($result['artifacts'] ?? null) ? $result['artifacts'] : []);

            if ($succeeded === null) {
                continue;
            }

            return [
                'preferred_variant_id' => $succeeded['variant_id'],
                'preferred_language_label' => $succeeded['language_label'],
                'preferred_release_id' => $succeeded['release_id'],
                'package_key' => $succeeded['package_key'],
                'preference_source_job_uuid' => (string) $job->job_uuid,
                'preference_updated_at' => $job->finished_at?->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Reorder pinned variants so a compatible learned preference is attempted first.
     *
     * @param  list<array<string, mixed>>  $pinnedVariants
     * @return array{
     *     script_variants: list<array<string, mixed>>,
     *     preferred_variant: array<string, mixed>|null
     * }
     */
    public function applyPreferenceToVariants(
        array $pinnedVariants,
        string $installationId,
        ?int $accountId,
        string $actionTagKey,
        ?string $variantGroupUuid,
    ): array {
        $variants = array_values($pinnedVariants);
        if ($variants === []) {
            return ['script_variants' => [], 'preferred_variant' => null];
        }

        $pref = $this->resolveForAccount($installationId, $accountId, $actionTagKey, $variantGroupUuid);
        if ($pref === null) {
            return ['script_variants' => $variants, 'preferred_variant' => null];
        }

        $matchIdx = null;
        foreach ($variants as $idx => $v) {
            if (! is_array($v)) {
                continue;
            }
            if ((string) ($v['variant_id'] ?? '') === $pref['preferred_variant_id']) {
                $matchIdx = $idx;
                break;
            }
        }

        if ($matchIdx === null) {
            return ['script_variants' => $variants, 'preferred_variant' => null];
        }

        $preferredRow = $variants[$matchIdx];
        $reordered = [$preferredRow];
        foreach ($variants as $idx => $v) {
            if ($idx !== $matchIdx) {
                $reordered[] = $v;
            }
        }

        return [
            'script_variants' => $reordered,
            'preferred_variant' => [
                'variant_id' => $pref['preferred_variant_id'],
                'language_label' => $pref['preferred_language_label'],
                'package_key' => (string) ($preferredRow['package_key'] ?? $pref['package_key']),
                'release_id' => (int) ($preferredRow['release_id'] ?? $pref['preferred_release_id']),
                'source_job_uuid' => $pref['preference_source_job_uuid'],
                'updated_at' => $pref['preference_updated_at'],
            ],
        ];
    }

    /**
     * List account-scoped preferences for an Action Tag + Variant Group in Staff DTO.
     *
     * @return list<array{
     *     account_id: int,
     *     account_name: string|null,
     *     preferred_variant_id: string,
     *     preferred_language_label: string,
     *     preferred_release_id: int,
     *     preference_source_job_uuid: string,
     *     preference_updated_at: string|null
     * }>
     */
    public function listPreferencesForActionTag(
        string $installationId,
        string $actionTagKey,
        ?string $variantGroupUuid,
    ): array {
        if ($variantGroupUuid === null || trim($variantGroupUuid) === '' || trim($actionTagKey) === '' || ! SocialAutomationSchema::jobsReady()) {
            return [];
        }

        $jobs = SaJob::query()
            ->where('status', JobStatus::Completed->value)
            ->whereNotNull('account_id')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'job_uuid', 'account_id', 'payload', 'result', 'finished_at']);

        $byAccount = [];
        foreach ($jobs as $job) {
            $aid = (int) $job->account_id;
            if ($aid <= 0 || isset($byAccount[$aid])) {
                continue;
            }
            $payload = is_array($job->payload) ? $job->payload : [];
            if ((string) ($payload['installation_id'] ?? '') !== $installationId) {
                continue;
            }
            if (strcasecmp((string) ($payload['action_tag_key'] ?? ''), $actionTagKey) !== 0) {
                continue;
            }
            if ((string) ($payload['variant_group_id'] ?? '') !== $variantGroupUuid) {
                continue;
            }

            $pinned = is_array($payload['script_variants'] ?? null) ? $payload['script_variants'] : [];
            $result = is_array($job->result) ? $job->result : [];
            $succeeded = is_array($result['succeeded_variant'] ?? null)
                ? $this->matchPinnedVariant($result['succeeded_variant'], $pinned)
                : $this->extractSucceededVariant($result, $pinned, is_array($result['artifacts'] ?? null) ? $result['artifacts'] : []);

            if ($succeeded === null) {
                continue;
            }

            $byAccount[$aid] = [
                'account_id' => $aid,
                'preferred_variant_id' => $succeeded['variant_id'],
                'preferred_language_label' => $succeeded['language_label'],
                'preferred_release_id' => $succeeded['release_id'],
                'preference_source_job_uuid' => (string) $job->job_uuid,
                'preference_updated_at' => $job->finished_at?->toIso8601String(),
            ];
        }

        if ($byAccount === []) {
            return [];
        }

        $accountNames = [];
        if (SocialAutomationSchema::has('sa_accounts')) {
            $accountNames = SaAccount::query()
                ->whereIn('id', array_keys($byAccount))
                ->pluck('name', 'id')
                ->all();
        }

        $out = [];
        foreach ($byAccount as $aid => $row) {
            $out[] = [
                'account_id' => $aid,
                'account_name' => isset($accountNames[$aid]) ? (string) $accountNames[$aid] : null,
                'preferred_variant_id' => $row['preferred_variant_id'],
                'preferred_language_label' => $row['preferred_language_label'],
                'preferred_release_id' => $row['preferred_release_id'],
                'preference_source_job_uuid' => $row['preference_source_job_uuid'],
                'preference_updated_at' => $row['preference_updated_at'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $pinnedVariants
     * @return array{variant_id: string, language_label: string, package_key: string, release_id: int}|null
     */
    private function matchPinnedVariant(array $candidate, array $pinnedVariants): ?array
    {
        $candId = trim((string) ($candidate['variant_id'] ?? ''));
        $candRelId = isset($candidate['release_id']) && is_numeric($candidate['release_id'])
            ? (int) $candidate['release_id']
            : 0;
        $candPkg = trim((string) ($candidate['package_key'] ?? ''));

        foreach ($pinnedVariants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $vId = trim((string) ($v['variant_id'] ?? ''));
            $vRelId = (int) ($v['release_id'] ?? 0);
            $vPkg = trim((string) ($v['package_key'] ?? ''));

            if ($candId !== '' && $vId !== '' && $candId === $vId) {
                return [
                    'variant_id' => $vId,
                    'language_label' => (string) ($v['language_label'] ?? $candidate['language_label'] ?? ''),
                    'package_key' => $vPkg,
                    'release_id' => $vRelId,
                ];
            }

            if ($candId === '' && $candRelId > 0 && $candRelId === $vRelId && ($candPkg === '' || $candPkg === $vPkg)) {
                return [
                    'variant_id' => $vId,
                    'language_label' => (string) ($v['language_label'] ?? $candidate['language_label'] ?? ''),
                    'package_key' => $vPkg,
                    'release_id' => $vRelId,
                ];
            }
        }

        return null;
    }
}
