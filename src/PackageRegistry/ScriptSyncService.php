<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaAuditLog;
use Omnichannel\Addons\SocialAutomation\Models\SaPackage;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageDependency;
use Omnichannel\Addons\SocialAutomation\Models\SaPackageRelease;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseAsset;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptReleaseMedia;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Orchestrates the two user-selected Script synchronization modes:
 * - normal: immutable release update under the same Script identity
 * - new_version: clone working definition into a new successor Script identity
 */
final class ScriptSyncService
{
    public const MODE_NORMAL = 'normal';

    public const MODE_NEW_VERSION = 'new_version';

    public const SUPERSEDED_REASON = 'superseded_by_new_version';

    public function __construct(
        private readonly PackagePublishValidator $validator = new PackagePublishValidator,
        private readonly PackageRegistryService $registry = new PackageRegistryService(new PackagePublishValidator),
        private readonly ScriptReleaseVersionPolicy $versionPolicy = new ScriptReleaseVersionPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{user_id?: int|null, sync_source?: string|null}  $audit
     * @return array{http: int, body: array<string, mixed>}
     */
    public function sync(array $input, array $audit = []): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if (! in_array($mode, [self::MODE_NORMAL, self::MODE_NEW_VERSION], true)) {
            return $this->errorResponse($mode !== '' ? $mode : null, 422, ['sync_mode_invalid']);
        }

        if (! $this->tablesReady()) {
            return $this->errorResponse($mode, 503, ['package_registry_unavailable']);
        }

        if ($mode === self::MODE_NORMAL) {
            return $this->syncNormal($input, $audit);
        }

        return $this->syncNewVersion($input, $audit);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{user_id?: int|null, sync_source?: string|null}  $audit
     * @return array{http: int, body: array<string, mixed>}
     */
    private function syncNormal(array $input, array $audit): array
    {
        $packageKey = trim((string) ($input['package_key'] ?? $input['source_package_key'] ?? ''));
        if ($packageKey === '') {
            return $this->errorResponse(self::MODE_NORMAL, 422, ['package_key_required']);
        }

        $package = SaPackage::query()
            ->where('package_key', $packageKey)
            ->where('kind', 'script')
            ->first();

        if (! $package instanceof SaPackage) {
            return $this->errorResponse(self::MODE_NORMAL, 404, ['script_not_found']);
        }

        $latest = $this->latestScriptRelease((int) $package->id);
        if (! $latest instanceof SaPackageRelease) {
            return $this->errorResponse(self::MODE_NORMAL, 404, ['script_release_not_found']);
        }

        $latestManifest = is_array($latest->manifest) ? $latest->manifest : [];
        $manifest = $this->buildNormalManifest($package, $latestManifest, $input);
        $name = trim((string) ($input['name'] ?? $package->name));
        $description = array_key_exists('description', $input)
            ? ($input['description'] !== null ? trim((string) $input['description']) : null)
            : $package->description;
        $schemaVersion = (int) ($input['schema_version'] ?? $latest->schema_version ?? 1);
        $runtime = array_key_exists('runtime_compatibility', $input)
            ? $input['runtime_compatibility']
            : $latest->runtime_compatibility;
        $dependencies = array_key_exists('dependencies', $input)
            ? (is_array($input['dependencies']) ? $input['dependencies'] : [])
            : $this->dependenciesForRelease($latest);
        $isAuthentication = array_key_exists('is_authentication', $input)
            ? filter_var($input['is_authentication'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($package->is_authentication ?? false);

        $latestDeps = $this->dependenciesForRelease($latest);
        $incomingFingerprint = $this->contentFingerprint(
            $name,
            $description,
            $schemaVersion,
            is_array($runtime) ? $runtime : [],
            $dependencies,
            $manifest,
        );
        $latestFingerprint = $this->contentFingerprint(
            (string) $package->name,
            $package->description,
            (int) $latest->schema_version,
            is_array($latest->runtime_compatibility) ? $latest->runtime_compatibility : [],
            $latestDeps,
            $latestManifest,
        );

        $requestedVersion = isset($input['version']) ? trim((string) $input['version']) : '';
        if (PackageDigest::equals($incomingFingerprint, $latestFingerprint)
            && ($requestedVersion === '' || $requestedVersion === (string) $latest->version)) {
            $demoReady = $this->releaseHasReadyDemo((int) $latest->id, $latestManifest);

            return [
                'http' => 200,
                'body' => [
                    'ok' => true,
                    'mode' => self::MODE_NORMAL,
                    'outcome' => 'no_changes',
                    'changed' => false,
                    'idempotent' => true,
                    'package_key' => (string) $package->package_key,
                    'script_id' => (string) ($latestManifest['script_id'] ?? $package->package_key),
                    'release_id' => (int) $latest->id,
                    'version' => (string) $latest->version,
                    'status' => (string) $latest->status,
                    'content_digest' => (string) $latest->content_digest,
                    'execution_digest' => (string) ($latest->execution_digest ?? ScriptExecutionDigest::fromManifest($latestManifest)),
                    'demo_required' => ! $demoReady,
                    'predecessor_disabled' => false,
                ],
            ];
        }

        $nextVersion = $requestedVersion !== ''
            ? $requestedVersion
            : $this->versionPolicy->nextVersionAfter((string) $latest->version);

        $publishPayload = [
            'package_key' => (string) $package->package_key,
            'kind' => 'script',
            'name' => $name,
            'description' => $description,
            'version' => $nextVersion,
            'schema_version' => $schemaVersion,
            'runtime_compatibility' => is_array($runtime) ? $runtime : null,
            'manifest' => $manifest,
            'dependencies' => $dependencies,
            'is_authentication' => $isAuthentication,
        ];

        $allowMissingDemo = ! isset($manifest['video_demo']);
        $writtenStoragePaths = [];
        $conn = SocialAutomationServiceConfig::CONNECTION;

        try {
            $outcome = DB::connection($conn)->transaction(function () use (
                $package,
                $latest,
                $publishPayload,
                $audit,
                $allowMissingDemo,
                &$writtenStoragePaths
            ): array {
                // Preserve operational metadata & Active/Disabled state across Normal Sync.
                $lockedPkg = SaPackage::query()->where('id', $package->id)->lockForUpdate()->first() ?? $package;
                $preservedOperational = [
                    'is_enabled' => (bool) ($lockedPkg->is_enabled ?? true),
                    'disabled_reason' => $lockedPkg->disabled_reason,
                    'disabled_meta' => $lockedPkg->disabled_meta,
                    'is_primary' => (bool) ($lockedPkg->is_primary ?? false),
                    'sharing_mode' => $lockedPkg->sharing_mode,
                    'global_cms_type' => $lockedPkg->global_cms_type,
                    'global_cms_template_id' => $lockedPkg->global_cms_template_id,
                    'global_cms_path_key' => $lockedPkg->global_cms_path_key,
                    'global_scope_key' => $lockedPkg->global_scope_key,
                    'language_label' => $lockedPkg->language_label,
                ];

                $result = $this->registry->publish(
                    $publishPayload,
                    [
                        'user_id' => $audit['user_id'] ?? null,
                        'sync_source' => $audit['sync_source'] ?? 'rpa_admin',
                    ],
                    ['allow_missing_video_demo' => $allowMissingDemo],
                );

                if (! in_array($result->outcome, [PackagePublishResult::OUTCOME_CREATED, PackagePublishResult::OUTCOME_IDEMPOTENT], true)) {
                    $extra = [];
                    if ($result->suggestedVersion !== null) {
                        $extra['suggested_version'] = $result->suggestedVersion;
                    }
                    if ($result->existingDigest !== null) {
                        $extra['existing_digest'] = $result->existingDigest;
                    }

                    return $this->errorResponse(
                        self::MODE_NORMAL,
                        $result->httpStatus(),
                        $result->errors !== [] ? $result->errors : ['normal_sync_failed'],
                        $extra,
                    );
                }

                $lockedPkg->fill($preservedOperational)->save();

                $releaseArr = $result->release ?? [];
                $newRelease = SaPackageRelease::query()
                    ->where('package_id', $lockedPkg->id)
                    ->where('version', (string) ($releaseArr['version'] ?? $publishPayload['version']))
                    ->first();

                if (! $newRelease instanceof SaPackageRelease) {
                    throw new \RuntimeException('normal_sync_release_missing_after_publish');
                }

                $idempotent = (bool) ($releaseArr['idempotent'] ?? false);
                $demoPreserved = false;
                $assetsComplete = $this->assetsSatisfiedForRelease($newRelease);

                if (! $idempotent) {
                    $this->cloneMatchingAssets($latest, $newRelease, $writtenStoragePaths);
                    $demoPreserved = $this->cloneReleaseMedia($latest, $newRelease, $writtenStoragePaths);
                    $assetsComplete = $this->assetsSatisfiedForRelease($newRelease);

                    if ($demoPreserved && $assetsComplete && $newRelease->status !== 'published') {
                        $newRelease->fill([
                            'status' => 'published',
                            'published_at' => now(),
                        ])->save();
                    }

                    $this->recordAudit(
                        $audit['user_id'] ?? null,
                        'script.sync.normal',
                        'sa_package_release',
                        (int) $newRelease->id,
                        [
                            'package_key' => (string) $lockedPkg->package_key,
                            'previous_release_id' => (int) $latest->id,
                            'previous_version' => (string) $latest->version,
                            'new_version' => (string) $newRelease->version,
                        ],
                    );
                } else {
                    $demoPreserved = $this->releaseHasReadyDemo(
                        (int) $newRelease->id,
                        is_array($newRelease->manifest) ? $newRelease->manifest : [],
                    );
                }

                $newManifest = is_array($newRelease->manifest) ? $newRelease->manifest : [];

                return [
                    'http' => $idempotent ? 200 : 201,
                    'body' => [
                        'ok' => true,
                        'mode' => self::MODE_NORMAL,
                        'outcome' => 'synced',
                        'changed' => ! $idempotent,
                        'idempotent' => $idempotent,
                        'package_key' => (string) $lockedPkg->package_key,
                        'script_id' => (string) ($newManifest['script_id'] ?? $lockedPkg->package_key),
                        'release_id' => (int) $newRelease->id,
                        'version' => (string) $newRelease->version,
                        'previous_release_id' => (int) $latest->id,
                        'previous_version' => (string) $latest->version,
                        'status' => (string) $newRelease->status,
                        'content_digest' => (string) $newRelease->content_digest,
                        'execution_digest' => (string) ($newRelease->execution_digest ?? ''),
                        'assets_complete' => $assetsComplete,
                        'demo_preserved' => $demoPreserved,
                        'demo_required' => ! $demoPreserved,
                        'predecessor_disabled' => false,
                        'release' => $this->releasePayload($lockedPkg, $newRelease, $idempotent),
                    ],
                ];
            });

            if (! ($outcome['body']['ok'] ?? false)) {
                $this->cleanupWrittenFiles($writtenStoragePaths);
            }

            return $outcome;
        } catch (Throwable $e) {
            $this->safeReport($e);
            $this->cleanupWrittenFiles($writtenStoragePaths);

            return $this->errorResponse(self::MODE_NORMAL, 500, ['normal_sync_failed']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{user_id?: int|null, sync_source?: string|null}  $audit
     * @return array{http: int, body: array<string, mixed>}
     */
    private function syncNewVersion(array $input, array $audit): array
    {
        $sourceKey = trim((string) ($input['source_package_key'] ?? $input['package_key'] ?? ''));
        if ($sourceKey === '') {
            return $this->errorResponse(self::MODE_NEW_VERSION, 422, ['source_package_key_required']);
        }

        $predecessor = SaPackage::query()
            ->where('package_key', $sourceKey)
            ->where('kind', 'script')
            ->first();

        if (! $predecessor instanceof SaPackage) {
            return $this->errorResponse(self::MODE_NEW_VERSION, 404, ['script_not_found']);
        }

        $idempotencyKey = isset($input['idempotency_key']) ? trim((string) $input['idempotency_key']) : '';
        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;
        $requestedTargetKey = isset($input['target_package_key']) ? trim((string) $input['target_package_key']) : '';

        if ($requestedTargetKey !== '') {
            if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,126}[a-z0-9]$/', $requestedTargetKey)) {
                return $this->errorResponse(self::MODE_NEW_VERSION, 422, ['target_package_key_invalid']);
            }
            if ($requestedTargetKey === (string) $predecessor->package_key) {
                return $this->errorResponse(self::MODE_NEW_VERSION, 422, ['target_package_key_must_differ']);
            }
        }

        $writtenStoragePaths = [];
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $userId = isset($audit['user_id']) ? (int) $audit['user_id'] : null;
        $syncSource = isset($audit['sync_source']) && trim((string) $audit['sync_source']) !== ''
            ? trim((string) $audit['sync_source'])
            : 'rpa_admin';

        try {
            $outcome = DB::connection($conn)->transaction(function () use (
                $predecessor,
                $input,
                $idempotencyKey,
                $requestedTargetKey,
                $userId,
                $syncSource,
                $conn,
                &$writtenStoragePaths
            ): array {
                $lockedPred = SaPackage::query()->where('id', $predecessor->id)->lockForUpdate()->first();
                if (! $lockedPred instanceof SaPackage) {
                    return $this->errorResponse(self::MODE_NEW_VERSION, 404, ['script_not_found']);
                }

                $predRelease = $this->latestScriptRelease((int) $lockedPred->id);
                if (! $predRelease instanceof SaPackageRelease) {
                    return $this->errorResponse(self::MODE_NEW_VERSION, 404, ['script_release_not_found']);
                }

                $predManifest = is_array($predRelease->manifest) ? $predRelease->manifest : [];

                // Check idempotency / already-superseded guard before creating anything.
                $existingSuperseded = $this->checkAlreadySuperseded(
                    $lockedPred,
                    $predRelease,
                    $idempotencyKey,
                    $requestedTargetKey,
                );
                if ($existingSuperseded !== null) {
                    return $existingSuperseded;
                }

                $targetKey = $requestedTargetKey !== ''
                    ? $requestedTargetKey
                    : $this->generateSuccessorPackageKey((string) $lockedPred->package_key);

                if (SaPackage::query()->where('package_key', $targetKey)->exists()) {
                    return $this->errorResponse(self::MODE_NEW_VERSION, 409, ['target_package_key_already_exists']);
                }

                $predScriptId = (string) ($predManifest['script_id'] ?? $lockedPred->package_key);
                $targetScriptId = isset($input['target_script_id']) && trim((string) $input['target_script_id']) !== ''
                    ? trim((string) $input['target_script_id'])
                    : $this->deriveSuccessorScriptId($predScriptId, $targetKey);

                if ($targetScriptId === $predScriptId) {
                    return $this->errorResponse(self::MODE_NEW_VERSION, 422, ['target_script_id_must_differ']);
                }

                $successorManifest = $this->buildSuccessorManifest(
                    $lockedPred,
                    $predRelease,
                    $predManifest,
                    $targetScriptId,
                    $input,
                );

                $name = trim((string) ($input['name'] ?? $lockedPred->name));
                $description = array_key_exists('description', $input)
                    ? ($input['description'] !== null ? trim((string) $input['description']) : null)
                    : $lockedPred->description;
                $schemaVersion = (int) ($input['schema_version'] ?? $predRelease->schema_version ?? 1);
                $runtime = array_key_exists('runtime_compatibility', $input)
                    ? $input['runtime_compatibility']
                    : $predRelease->runtime_compatibility;
                $dependencies = array_key_exists('dependencies', $input)
                    ? (is_array($input['dependencies']) ? $input['dependencies'] : [])
                    : $this->dependenciesForRelease($predRelease);
                $isAuthentication = array_key_exists('is_authentication', $input)
                    ? filter_var($input['is_authentication'], FILTER_VALIDATE_BOOLEAN)
                    : (bool) ($lockedPred->is_authentication ?? false);

                $publishInput = [
                    'package_key' => $targetKey,
                    'kind' => 'script',
                    'name' => $name,
                    'description' => $description,
                    'version' => '1',
                    'schema_version' => $schemaVersion,
                    'runtime_compatibility' => is_array($runtime) ? $runtime : null,
                    'manifest' => $successorManifest,
                    'dependencies' => $dependencies,
                    'is_authentication' => $isAuthentication,
                ];

                $validated = $this->validator->validate($publishInput, ['allow_missing_video_demo' => true]);
                if (! ($validated['ok'] ?? false)) {
                    return $this->errorResponse(
                        self::MODE_NEW_VERSION,
                        422,
                        $validated['errors'] ?? ['validation_failed'],
                    );
                }

                /** @var array<string, mixed> $data */
                $data = $validated['data'];

                $actionTagKey = trim((string) ($successorManifest['action_tag_key'] ?? $input['action_tag_key'] ?? ''));
                if ($actionTagKey !== '' && Schema::connection($conn)->hasTable('sa_action_tags')) {
                    if (! SaActionTag::query()->where('tag_key', $actionTagKey)->exists()) {
                        return $this->errorResponse(self::MODE_NEW_VERSION, 422, ['action_tag_not_found']);
                    }
                }

                // 1. Create successor SaPackage (never overwrites predecessor; Variant Group mappings untouched).
                $successor = SaPackage::query()->create([
                    'package_key' => $data['package_key'],
                    'kind' => 'script',
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'language_label' => $lockedPred->language_label,
                    'is_enabled' => true,
                    'is_primary' => false,
                    'is_authentication' => (bool) $data['is_authentication'],
                    'sharing_mode' => SaPackage::SHARING_SITE_SPECIFIC,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'sync_source' => $syncSource,
                ]);

                // 2. Create immutable successor Release v1 in pending_media (no Demo carried over).
                $executionDigest = ScriptExecutionDigest::fromManifest($data['manifest']);
                $successorRelease = SaPackageRelease::query()->create([
                    'package_id' => $successor->id,
                    'version' => '1',
                    'content_digest' => $data['content_digest'],
                    'execution_digest' => $executionDigest,
                    'schema_version' => $data['schema_version'],
                    'runtime_compatibility' => $data['runtime_compatibility'],
                    'manifest' => $data['manifest'],
                    'status' => 'pending_media',
                    'published_at' => null,
                    'created_by_user_id' => $userId,
                    'sync_source' => $syncSource,
                ]);

                foreach ($data['dependencies'] as $dep) {
                    SaPackageDependency::query()->create([
                        'release_id' => $successorRelease->id,
                        'dependency_package_key' => $dep['package_key'],
                        'dependency_version' => $dep['version'],
                        'dependency_digest' => $dep['content_digest'],
                    ]);
                }

                if ($actionTagKey !== '' && Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
                    $tag = SaActionTag::query()->where('tag_key', $actionTagKey)->first();
                    if ($tag instanceof SaActionTag) {
                        SaActionTagScript::query()->firstOrCreate([
                            'action_tag_id' => $tag->id,
                            'script_id' => $targetScriptId,
                            'script_version' => '1',
                        ], [
                            'action_tag_key' => $tag->tag_key,
                            'package_id' => $successor->id,
                            'release_id' => $successorRelease->id,
                        ]);
                    }
                }

                // 3. Copy reusable execution assets (never Demo media).
                $this->cloneMatchingAssets($predRelease, $successorRelease, $writtenStoragePaths);
                $assetsComplete = $this->assetsSatisfiedForRelease($successorRelease);

                // Optional test/fault-injection hook for verifying failure safety before predecessor disable.
                if (isset($input['_simulate_failure_before_disable']) && $input['_simulate_failure_before_disable'] === true) {
                    throw new \RuntimeException('simulated_failure_before_disable');
                }

                // 4. Disable predecessor ONLY AFTER successor creation & assets succeed.
                $lockedPred->fill([
                    'is_enabled' => false,
                    'disabled_reason' => self::SUPERSEDED_REASON,
                    'disabled_meta' => [
                        'successor_package_key' => (string) $successor->package_key,
                        'successor_script_id' => $targetScriptId,
                        'successor_release_id' => (int) $successorRelease->id,
                        'successor_version' => (string) $successorRelease->version,
                        'successor_content_digest' => (string) $successorRelease->content_digest,
                        'predecessor_release_id' => (int) $predRelease->id,
                        'idempotency_key' => $idempotencyKey,
                        'superseded_at' => now()->toIso8601String(),
                    ],
                    'updated_by_user_id' => $userId,
                ])->save();

                $this->recordAudit(
                    $userId,
                    'script.sync.new_version',
                    'sa_package',
                    (int) $successor->id,
                    [
                        'predecessor_package_key' => (string) $lockedPred->package_key,
                        'predecessor_release_id' => (int) $predRelease->id,
                        'successor_package_key' => (string) $successor->package_key,
                        'successor_release_id' => (int) $successorRelease->id,
                    ],
                );

                return [
                    'http' => 201,
                    'body' => [
                        'ok' => true,
                        'mode' => self::MODE_NEW_VERSION,
                        'outcome' => 'new_version_created',
                        'changed' => true,
                        'idempotent' => false,
                        'package_key' => (string) $successor->package_key,
                        'script_id' => $targetScriptId,
                        'release_id' => (int) $successorRelease->id,
                        'version' => (string) $successorRelease->version,
                        'status' => (string) $successorRelease->status,
                        'content_digest' => (string) $successorRelease->content_digest,
                        'execution_digest' => $executionDigest,
                        'assets_complete' => $assetsComplete,
                        'demo_required' => true,
                        'predecessor_package_key' => (string) $lockedPred->package_key,
                        'predecessor_release_id' => (int) $predRelease->id,
                        'predecessor_disabled' => true,
                        'release' => $this->releasePayload($successor, $successorRelease, false),
                    ],
                ];
            });

            if (! ($outcome['body']['ok'] ?? false)) {
                $this->cleanupWrittenFiles($writtenStoragePaths);
            }

            return $outcome;
        } catch (Throwable $e) {
            $this->safeReport($e);
            $this->cleanupWrittenFiles($writtenStoragePaths);

            return $this->errorResponse(self::MODE_NEW_VERSION, 500, ['new_version_sync_failed']);
        }
    }

    /**
     * @return array{http: int, body: array<string, mixed>}|null
     */
    private function checkAlreadySuperseded(
        SaPackage $predecessor,
        SaPackageRelease $predRelease,
        ?string $idempotencyKey,
        string $requestedTargetKey,
    ): ?array {
        if ($predecessor->disabled_reason !== self::SUPERSEDED_REASON) {
            return null;
        }

        $meta = is_array($predecessor->disabled_meta) ? $predecessor->disabled_meta : [];
        $successorKey = trim((string) ($meta['successor_package_key'] ?? ''));
        if ($successorKey === '') {
            return null;
        }

        $successor = SaPackage::query()->where('package_key', $successorKey)->where('kind', 'script')->first();
        $successorReleaseId = (int) ($meta['successor_release_id'] ?? 0);
        $successorRelease = $successorReleaseId > 0 ? SaPackageRelease::query()->find($successorReleaseId) : null;

        $storedIdemKey = isset($meta['idempotency_key']) ? (string) $meta['idempotency_key'] : null;
        $isIdempotentMatch = false;
        if ($idempotencyKey !== null && $storedIdemKey !== null && hash_equals($storedIdemKey, $idempotencyKey)) {
            $isIdempotentMatch = true;
        } elseif ($idempotencyKey === null
            && (int) ($meta['predecessor_release_id'] ?? 0) === (int) $predRelease->id
            && ($requestedTargetKey === '' || $requestedTargetKey === $successorKey)) {
            $isIdempotentMatch = true;
        }

        if (! $isIdempotentMatch || ! $successor instanceof SaPackage || ! $successorRelease instanceof SaPackageRelease) {
            return $this->errorResponse(
                self::MODE_NEW_VERSION,
                409,
                ['predecessor_already_superseded'],
                ['successor_package_key' => $successorKey],
            );
        }

        $succManifest = is_array($successorRelease->manifest) ? $successorRelease->manifest : [];

        return [
            'http' => 200,
            'body' => [
                'ok' => true,
                'mode' => self::MODE_NEW_VERSION,
                'outcome' => 'new_version_created',
                'changed' => false,
                'idempotent' => true,
                'package_key' => (string) $successor->package_key,
                'script_id' => (string) ($succManifest['script_id'] ?? $successor->package_key),
                'release_id' => (int) $successorRelease->id,
                'version' => (string) $successorRelease->version,
                'status' => (string) $successorRelease->status,
                'content_digest' => (string) $successorRelease->content_digest,
                'execution_digest' => (string) ($successorRelease->execution_digest ?? ''),
                'assets_complete' => $this->assetsSatisfiedForRelease($successorRelease),
                'demo_required' => ! $this->releaseHasReadyDemo((int) $successorRelease->id, $succManifest),
                'predecessor_package_key' => (string) $predecessor->package_key,
                'predecessor_release_id' => (int) $predRelease->id,
                'predecessor_disabled' => ! (bool) ($predecessor->is_enabled ?? true),
                'release' => $this->releasePayload($successor, $successorRelease, true),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $latestManifest
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildNormalManifest(SaPackage $package, array $latestManifest, array $input): array
    {
        $incomingManifest = is_array($input['manifest'] ?? null) ? $input['manifest'] : null;
        $manifest = $incomingManifest !== null ? $incomingManifest : $latestManifest;

        // Preserve Script identity unless explicitly matching.
        $manifest['script_id'] = (string) ($latestManifest['script_id'] ?? $package->package_key);

        foreach (['steps', 'variables', 'outputs', 'assets', 'runtime_requirements', 'default_pattern', 'zoom', 'groups'] as $field) {
            if ($incomingManifest === null && array_key_exists($field, $input)) {
                $manifest[$field] = $input[$field];
            }
        }

        // Preserve existing Demo association in Normal Sync when omitted.
        if (! array_key_exists('video_demo', $manifest) && array_key_exists('video_demo', $latestManifest)) {
            $manifest['video_demo'] = $latestManifest['video_demo'];
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $predManifest
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildSuccessorManifest(
        SaPackage $predecessor,
        SaPackageRelease $predRelease,
        array $predManifest,
        string $targetScriptId,
        array $input,
    ): array {
        $incomingManifest = is_array($input['manifest'] ?? null) ? $input['manifest'] : null;
        $manifest = $incomingManifest !== null ? array_merge($predManifest, $incomingManifest) : $predManifest;

        foreach (['steps', 'variables', 'outputs', 'assets', 'runtime_requirements', 'default_pattern', 'zoom', 'groups'] as $field) {
            if ($incomingManifest === null && array_key_exists($field, $input)) {
                $manifest[$field] = $input[$field];
            }
        }

        // Never carry over Demo association or old request binding to successor.
        unset($manifest['video_demo'], $manifest['script_request_id']);

        $manifest['script_id'] = $targetScriptId;
        $manifest['predecessor_package_key'] = (string) $predecessor->package_key;
        $manifest['predecessor_release_id'] = (int) $predRelease->id;

        return $manifest;
    }

    /**
     * @param  list<string>  $writtenStoragePaths
     */
    private function cloneMatchingAssets(SaPackageRelease $source, SaPackageRelease $target, array &$writtenStoragePaths): void
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_assets')) {
            return;
        }

        $declared = is_array($target->manifest['assets'] ?? null) ? $target->manifest['assets'] : [];
        if ($declared === []) {
            return;
        }

        $sourceAssets = SaScriptReleaseAsset::query()->where('release_id', $source->id)->get()->keyBy('asset_path');
        foreach ($declared as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            $sha = strtolower(trim((string) ($asset['sha256'] ?? '')));
            if ($path === '' || $sha === '') {
                continue;
            }

            $srcRow = $sourceAssets->get($path);
            if (! $srcRow instanceof SaScriptReleaseAsset) {
                continue;
            }
            if (! hash_equals(strtolower((string) $srcRow->checksum_sha256), $sha)) {
                continue;
            }
            if (! Storage::disk('local')->exists((string) $srcRow->storage_path)) {
                continue;
            }

            $bytes = Storage::disk('local')->get((string) $srcRow->storage_path);
            if (! is_string($bytes) || ! hash_equals(hash('sha256', $bytes), $sha)) {
                throw new \RuntimeException('reusable_asset_integrity_mismatch:'.$path);
            }

            $destPath = sprintf('social-automation/script-assets/%d/%s', (int) $target->id, $sha);
            if (! Storage::disk('local')->put($destPath, $bytes)) {
                throw new \RuntimeException('reusable_asset_storage_write_failed:'.$path);
            }
            $writtenStoragePaths[] = $destPath;

            SaScriptReleaseAsset::query()->updateOrCreate(
                ['release_id' => $target->id, 'asset_path' => $path],
                [
                    'checksum_sha256' => $sha,
                    'mime' => (string) ($srcRow->mime ?: 'application/octet-stream'),
                    'storage_path' => $destPath,
                    'size_bytes' => strlen($bytes),
                ],
            );
        }
    }

    /**
     * @param  list<string>  $writtenStoragePaths
     */
    private function cloneReleaseMedia(SaPackageRelease $source, SaPackageRelease $target, array &$writtenStoragePaths): bool
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_media')) {
            return false;
        }

        $rows = SaScriptReleaseMedia::query()
            ->where('release_id', $source->id)
            ->where('status', SaScriptReleaseMedia::STATUS_READY)
            ->get();

        $hasVideo = false;
        $hasThumb = false;

        foreach ($rows as $row) {
            $disk = Storage::disk($row->storage_disk ?: 'local');
            if ($row->storage_path === '' || ! $disk->exists($row->storage_path)) {
                continue;
            }
            $bytes = $disk->get($row->storage_path);
            if (! is_string($bytes)) {
                continue;
            }

            $destPath = sprintf(
                'social-automation/script-media/%d/%s/%s',
                (int) $target->id,
                (string) $target->version,
                basename($row->storage_path),
            );
            if (! Storage::disk('local')->put($destPath, $bytes)) {
                throw new \RuntimeException('release_media_storage_write_failed:'.$row->kind);
            }
            $writtenStoragePaths[] = $destPath;

            SaScriptReleaseMedia::query()->updateOrCreate(
                ['release_id' => $target->id, 'kind' => $row->kind],
                [
                    'storage_disk' => 'local',
                    'storage_path' => $destPath,
                    'mime' => $row->mime,
                    'size_bytes' => strlen($bytes),
                    'checksum_sha256' => $row->checksum_sha256 ?: hash('sha256', $bytes),
                    'status' => SaScriptReleaseMedia::STATUS_READY,
                    'metadata' => array_merge(is_array($row->metadata) ? $row->metadata : [], [
                        'preserved_from_release_id' => (int) $source->id,
                    ]),
                ],
            );

            if ($row->kind === SaScriptReleaseMedia::KIND_VIDEO_DEMO) {
                $hasVideo = true;
            } elseif ($row->kind === SaScriptReleaseMedia::KIND_THUMBNAIL) {
                $hasThumb = true;
            }
        }

        return $hasVideo && $hasThumb;
    }

    private function releaseHasReadyDemo(int $releaseId, array $manifest): bool
    {
        if (Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_media')) {
            $kinds = SaScriptReleaseMedia::query()
                ->where('release_id', $releaseId)
                ->where('status', SaScriptReleaseMedia::STATUS_READY)
                ->pluck('kind')
                ->all();

            return in_array(SaScriptReleaseMedia::KIND_VIDEO_DEMO, $kinds, true)
                && in_array(SaScriptReleaseMedia::KIND_THUMBNAIL, $kinds, true);
        }

        return is_array($manifest['video_demo'] ?? null);
    }

    private function assetsSatisfiedForRelease(SaPackageRelease $release): bool
    {
        $declared = is_array($release->manifest['assets'] ?? null) ? $release->manifest['assets'] : [];
        if ($declared === []) {
            return true;
        }
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_script_release_assets')) {
            return false;
        }

        $uploaded = SaScriptReleaseAsset::query()->where('release_id', $release->id)->get()->keyBy('asset_path');

        foreach ($declared as $asset) {
            if (! is_array($asset)) {
                return false;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            $sha = strtolower(trim((string) ($asset['sha256'] ?? '')));
            $row = $uploaded->get($path);
            if (! $row instanceof SaScriptReleaseAsset || ! hash_equals(strtolower((string) $row->checksum_sha256), $sha)) {
                return false;
            }
        }

        return true;
    }

    private function latestScriptRelease(int $packageId): ?SaPackageRelease
    {
        $rows = SaPackageRelease::query()
            ->where('package_id', $packageId)
            ->whereIn('status', ['published', 'pending_media'])
            ->orderByDesc('id')
            ->get();

        $best = $this->versionPolicy->pickHighestOrdinal($rows);

        return $best instanceof SaPackageRelease ? $best : null;
    }

    /**
     * @return list<array{package_key: string, version: string, content_digest: string}>
     */
    private function dependenciesForRelease(SaPackageRelease $release): array
    {
        if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_package_dependencies')) {
            return [];
        }

        return SaPackageDependency::query()
            ->where('release_id', $release->id)
            ->get()
            ->map(static fn (SaPackageDependency $d): array => [
                'package_key' => (string) $d->dependency_package_key,
                'version' => (string) $d->dependency_version,
                'content_digest' => (string) $d->dependency_digest,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  list<array<string, mixed>>  $dependencies
     * @param  array<string, mixed>  $manifest
     */
    private function contentFingerprint(
        string $name,
        ?string $description,
        int $schemaVersion,
        array $runtime,
        array $dependencies,
        array $manifest,
    ): string {
        return PackageDigest::sha256OfCanonicalJson([
            'name' => $name,
            'description' => $description,
            'schema_version' => $schemaVersion,
            'runtime_compatibility' => $runtime,
            'dependencies' => $dependencies,
            'manifest' => $manifest,
        ]);
    }

    private function generateSuccessorPackageKey(string $predecessorKey): string
    {
        $base = preg_replace('/-v[0-9]+$/', '', $predecessorKey) ?: $predecessorKey;
        $n = 2;
        while ($n < 10000) {
            $suffix = '-v'.$n;
            $maxBase = 128 - strlen($suffix);
            $trimmedBase = substr($base, 0, $maxBase);
            $trimmedBase = rtrim($trimmedBase, '.-_');
            $candidate = $trimmedBase.$suffix;
            if (! SaPackage::query()->where('package_key', $candidate)->exists()) {
                return $candidate;
            }
            $n++;
        }

        return substr($base, 0, 110).'-v'.time();
    }

    private function deriveSuccessorScriptId(string $predScriptId, string $targetPackageKey): string
    {
        if ($targetPackageKey !== $predScriptId) {
            return $targetPackageKey;
        }

        return $predScriptId.'-v2';
    }

    /**
     * @return array<string, mixed>
     */
    private function releasePayload(SaPackage $package, SaPackageRelease $release, bool $idempotent): array
    {
        return [
            'release_id' => (int) $release->id,
            'package_key' => (string) $package->package_key,
            'kind' => (string) $package->kind,
            'name' => (string) $package->name,
            'version' => (string) $release->version,
            'content_digest' => (string) $release->content_digest,
            'execution_digest' => (string) ($release->execution_digest ?? ''),
            'schema_version' => (int) $release->schema_version,
            'status' => (string) $release->status,
            'idempotent' => $idempotent,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $extra
     * @return array{http: int, body: array<string, mixed>}
     */
    private function errorResponse(?string $mode, int $http, array $errors, array $extra = []): array
    {
        return [
            'http' => $http,
            'body' => array_merge([
                'ok' => false,
                'mode' => $mode,
                'outcome' => 'error',
                'errors' => array_values(array_unique($errors)),
                'action_required' => true,
                'predecessor_disabled' => false,
            ], $extra),
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function cleanupWrittenFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if ($path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            } catch (Throwable) {
                // Best-effort cleanup during failure recovery.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordAudit(?int $userId, string $action, string $subjectType, int $subjectId, array $context): void
    {
        try {
            if (! Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_audit_logs')) {
                return;
            }
            SaAuditLog::query()->create([
                'actor_type' => $userId !== null ? 'user' : 'system',
                'actor_id' => $userId !== null ? (string) $userId : null,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => (string) $subjectId,
                'context' => $context,
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit failure must not break sync.
        }
    }

    private function safeReport(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Ignore when ExceptionHandler is unavailable in isolated unit tests.
        }
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_packages')
                && Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable('sa_package_releases');
        } catch (Throwable) {
            return false;
        }
    }
}
