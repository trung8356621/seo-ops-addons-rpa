<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Pure validation for Action Tag Auth Script pins (sa_action_tags.auth_*).
 * Auth is never resolved via sa_action_tag_scripts.
 *
 * @phpstan-type PackageRow array{
 *     id: int,
 *     kind: string,
 *     is_authentication: bool,
 *     is_enabled: bool,
 *     package_key?: string
 * }
 * @phpstan-type ReleaseRow array{
 *     id: int,
 *     package_id: int,
 *     version: string,
 *     status: string,
 *     content_digest?: string|null
 * }
 */
final class AuthScriptPinPolicy
{
    /**
     * @param  PackageRow|null  $package
     * @param  ReleaseRow|null  $release
     * @return array{ok: true}|array{ok: false, errors: list<string>}
     */
    public function assertPinTarget(?array $package, ?array $release): array
    {
        if ($package === null) {
            return ['ok' => false, 'errors' => ['auth_script_not_found']];
        }
        if ($release === null) {
            return ['ok' => false, 'errors' => ['auth_release_not_found']];
        }
        if (strtolower((string) ($package['kind'] ?? '')) !== 'script') {
            return ['ok' => false, 'errors' => ['auth_script_kind_invalid']];
        }
        if (! ($package['is_authentication'] ?? false)) {
            return ['ok' => false, 'errors' => ['auth_script_not_authentication']];
        }
        if (! ($package['is_enabled'] ?? true)) {
            return ['ok' => false, 'errors' => ['auth_script_disabled']];
        }
        if ((int) $release['package_id'] !== (int) $package['id']) {
            return ['ok' => false, 'errors' => ['auth_release_package_mismatch']];
        }
        if (strtolower((string) ($release['status'] ?? '')) !== 'published') {
            return ['ok' => false, 'errors' => ['auth_release_not_published']];
        }

        return ['ok' => true];
    }

    /**
     * Client resolve/pull: pin must still match live package+release gates.
     *
     * @param  PackageRow|null  $package
     * @param  ReleaseRow|null  $release
     * @param  int|null  $pinnedPackageId
     * @param  int|null  $pinnedReleaseId
     * @return array{ok: true}|array{ok: false, errors: list<string>}
     */
    public function assertPinnedAuthRelease(
        ?array $package,
        ?array $release,
        ?int $pinnedPackageId,
        ?int $pinnedReleaseId,
    ): array {
        if ($pinnedPackageId === null || $pinnedReleaseId === null) {
            return ['ok' => false, 'errors' => ['auth_pin_missing']];
        }
        if ($package === null || $release === null) {
            return ['ok' => false, 'errors' => ['auth_pin_target_missing']];
        }
        if ((int) $package['id'] !== $pinnedPackageId || (int) $release['id'] !== $pinnedReleaseId) {
            return ['ok' => false, 'errors' => ['auth_pin_mismatch']];
        }

        return $this->assertPinTarget($package, $release);
    }
}
