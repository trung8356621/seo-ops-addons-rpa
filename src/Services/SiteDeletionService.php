<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTagScript;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Classifies Site dependencies into safe generated/unreferenced children vs
 * active/historical references, and performs transactional safe Site deletion.
 */
final class SiteDeletionService
{
    /**
     * @return array{
     *   can_delete: bool,
     *   blockers: array<string, int>,
     *   safe_children: array{paths: int, tags: int, pending_script_requests: int},
     *   dependencies: array{paths: int, tags: int, scripts: int, script_requests: int, campaigns: int}
     * }
     */
    public function analyze(SaSite $site): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;
        $siteKeyLower = strtolower((string) $site->key);

        $pathsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_paths')) {
            $pathsCount = SaPath::query()
                ->where(function ($q) use ($site): void {
                    $q->where('site_id', $site->id)
                        ->orWhere('site_key', $site->key);
                })
                ->count();
        }

        $tagIds = [];
        $tagKeys = [];
        $tagsCount = 0;
        $authPinsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_action_tags')) {
            $hasSiteId = Schema::connection($conn)->hasColumn('sa_action_tags', 'site_id');
            $hasAuthCols = Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_script_id');

            $cols = ['id', 'tag_key'];
            if ($hasAuthCols) {
                $cols[] = 'auth_script_id';
                $cols[] = 'auth_release_id';
            }

            $tags = SaActionTag::query()
                ->where(function ($q) use ($site, $hasSiteId): void {
                    if ($hasSiteId) {
                        $q->where('site_id', $site->id)
                            ->orWhere('site_key', $site->key)
                            ->orWhere('platform', $site->key);
                    } else {
                        $q->where('platform', $site->key);
                    }
                })
                ->get($cols);

            $tagsCount = $tags->count();
            $tagIds = $tags->pluck('id')->map(fn ($v) => (int) $v)->all();
            $tagKeys = $tags->pluck('tag_key')->map(fn ($v) => (string) $v)->all();

            if ($hasAuthCols) {
                $authPinsCount = $tags->filter(
                    fn (SaActionTag $t) => $t->auth_script_id !== null || $t->auth_release_id !== null
                )->count();
            }
        }

        $scriptsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_action_tag_scripts')) {
            $scriptsCount = SaActionTagScript::query()
                ->where(function ($q) use ($tagIds, $tagKeys, $site): void {
                    $q->where('action_tag_key', 'like', $site->key.'.%');
                    if ($tagIds !== []) {
                        $q->orWhereIn('action_tag_id', $tagIds);
                    }
                    if ($tagKeys !== []) {
                        $q->orWhereIn('action_tag_key', $tagKeys);
                    }
                })
                ->count();
        }

        $accountEntitlementsCount = 0;
        if ($tagIds !== [] && Schema::connection($conn)->hasTable('sa_account_action_tags')) {
            $accountEntitlementsCount = DB::connection($conn)
                ->table('sa_account_action_tags')
                ->whereIn('action_tag_id', $tagIds)
                ->count();
        }

        $pendingRequestsCount = 0;
        $activeRequestsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_script_requests')) {
            $hasSiteKeyCol = Schema::connection($conn)->hasColumn('sa_script_requests', 'site_key');
            $reqs = SaScriptRequest::query()
                ->where(function ($q) use ($tagIds, $tagKeys, $site, $hasSiteKeyCol): void {
                    $q->where('action_tag_key', 'like', $site->key.'.%');
                    if ($hasSiteKeyCol) {
                        $q->orWhere('site_key', $site->key);
                    }
                    if ($tagIds !== []) {
                        $q->orWhereIn('action_tag_id', $tagIds);
                    }
                    if ($tagKeys !== []) {
                        $q->orWhereIn('action_tag_key', $tagKeys);
                    }
                })
                ->get(['id', 'request_type', 'status', 'script_id', 'script_version']);

            foreach ($reqs as $r) {
                $isSafePending = ($r->status === SaScriptRequest::STATUS_PENDING)
                    && ($r->request_type === SaScriptRequest::TYPE_CREATE)
                    && ($r->script_id === null || $r->script_id === '')
                    && ($r->script_version === null || $r->script_version === '');

                if ($isSafePending) {
                    $pendingRequestsCount++;
                } else {
                    $activeRequestsCount++;
                }
            }
        }

        $campaignsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_campaigns')) {
            $campaignsCount = DB::connection($conn)
                ->table('sa_campaigns')
                ->where(function ($q) use ($siteKeyLower, $tagKeys, $site): void {
                    $q->whereRaw('LOWER(platform) = ?', [$siteKeyLower])
                        ->orWhere('script_id', 'like', $site->key.'.%');
                    if ($tagKeys !== []) {
                        $q->orWhereIn('script_id', $tagKeys);
                    }
                })
                ->count();
        }

        $jobsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_jobs')) {
            $jobsCount = DB::connection($conn)
                ->table('sa_jobs')
                ->where(function ($q) use ($tagKeys, $site): void {
                    $q->where('package_key', 'like', $site->key.'.%');
                    if ($tagKeys !== []) {
                        $q->orWhereIn('package_key', $tagKeys);
                    }
                })
                ->count();
        }

        $tasksCount = 0;
        if (Schema::connection($conn)->hasTable('sa_tasks')) {
            $tasksCount = DB::connection($conn)
                ->table('sa_tasks')
                ->where(function ($q) use ($tagKeys, $site): void {
                    $q->where('package_key', 'like', $site->key.'.%');
                    if ($tagKeys !== []) {
                        $q->orWhereIn('package_key', $tagKeys);
                    }
                })
                ->count();
        }

        $accountsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_accounts')) {
            $accountsCount = DB::connection($conn)
                ->table('sa_accounts')
                ->whereRaw('LOWER(platform) = ?', [$siteKeyLower])
                ->count();
        }

        $reportsCount = 0;
        if (Schema::connection($conn)->hasTable('sa_rpa_execution_reports')) {
            $hasScriptCol = Schema::connection($conn)->hasColumn('sa_rpa_execution_reports', 'script_id');
            if ($hasScriptCol) {
                $reportsCount = DB::connection($conn)
                    ->table('sa_rpa_execution_reports')
                    ->where(function ($q) use ($tagKeys, $site): void {
                        $q->where('script_id', 'like', $site->key.'.%');
                        if ($tagKeys !== []) {
                            $q->orWhereIn('script_id', $tagKeys);
                        }
                    })
                    ->count();
            }
        }

        $blockers = [
            'scripts' => $scriptsCount,
            'auth_pins' => $authPinsCount,
            'account_entitlements' => $accountEntitlementsCount,
            'active_script_requests' => $activeRequestsCount,
            'campaigns' => $campaignsCount,
            'jobs' => $jobsCount,
            'tasks' => $tasksCount,
            'accounts' => $accountsCount,
            'execution_reports' => $reportsCount,
        ];

        $safeChildren = [
            'paths' => $pathsCount,
            'tags' => $tagsCount,
            'pending_script_requests' => $pendingRequestsCount,
        ];

        $canDelete = array_sum($blockers) === 0;

        return [
            'can_delete' => $canDelete,
            'blockers' => $blockers,
            'safe_children' => $safeChildren,
            'dependencies' => [
                'paths' => $pathsCount,
                'tags' => $tagsCount,
                'scripts' => $scriptsCount,
                'script_requests' => $activeRequestsCount + $pendingRequestsCount,
                'campaigns' => $campaignsCount,
                'jobs' => $jobsCount,
                'tasks' => $tasksCount,
                'accounts' => $accountsCount,
            ],
        ];
    }

    /**
     * Delete a Site and its safe generated/unreferenced children inside a single transaction.
     * Blocks and preserves everything when any active or historical blocker exists.
     *
     * @return array<string, mixed>
     */
    public function deleteSafely(SaSite $site): array
    {
        $conn = SocialAutomationServiceConfig::CONNECTION;

        return DB::connection($conn)->transaction(function () use ($site, $conn): array {
            $analysis = $this->analyze($site);
            if (! $analysis['can_delete']) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'site_has_dependencies',
                    'message' => 'Không thể xóa Site vì còn Script bindings, Campaigns, Jobs hoặc dữ liệu lịch sử đang tham chiếu.',
                    'blockers' => $analysis['blockers'],
                    'safe_children' => $analysis['safe_children'],
                    'dependencies' => $analysis['dependencies'],
                ];
            }

            $deletedRequests = 0;
            $deletedTags = 0;
            $deletedPaths = 0;

            $tagIds = [];
            $tagKeys = [];
            if (Schema::connection($conn)->hasTable('sa_action_tags')) {
                $hasSiteId = Schema::connection($conn)->hasColumn('sa_action_tags', 'site_id');
                $tagsQuery = SaActionTag::query()->where(function ($q) use ($site, $hasSiteId): void {
                    if ($hasSiteId) {
                        $q->where('site_id', $site->id)
                            ->orWhere('site_key', $site->key)
                            ->orWhere('platform', $site->key);
                    } else {
                        $q->where('platform', $site->key);
                    }
                });
                $tags = $tagsQuery->get(['id', 'tag_key']);
                $tagIds = $tags->pluck('id')->map(fn ($v) => (int) $v)->all();
                $tagKeys = $tags->pluck('tag_key')->map(fn ($v) => (string) $v)->all();

                if (Schema::connection($conn)->hasTable('sa_script_requests')) {
                    $hasSiteKeyCol = Schema::connection($conn)->hasColumn('sa_script_requests', 'site_key');
                    $deletedRequests = SaScriptRequest::query()
                        ->where('status', SaScriptRequest::STATUS_PENDING)
                        ->where('request_type', SaScriptRequest::TYPE_CREATE)
                        ->whereNull('script_id')
                        ->where(function ($q) use ($tagIds, $tagKeys, $site, $hasSiteKeyCol): void {
                            $q->where('action_tag_key', 'like', $site->key.'.%');
                            if ($hasSiteKeyCol) {
                                $q->orWhere('site_key', $site->key);
                            }
                            if ($tagIds !== []) {
                                $q->orWhereIn('action_tag_id', $tagIds);
                            }
                            if ($tagKeys !== []) {
                                $q->orWhereIn('action_tag_key', $tagKeys);
                            }
                        })
                        ->delete();
                }

                if ($tagIds !== []) {
                    $deletedTags = SaActionTag::query()->whereIn('id', $tagIds)->delete();
                }
            }

            if (Schema::connection($conn)->hasTable('sa_paths')) {
                $deletedPaths = SaPath::query()
                    ->where(function ($q) use ($site): void {
                        $q->where('site_id', $site->id)
                            ->orWhere('site_key', $site->key);
                    })
                    ->delete();
            }

            $siteId = (int) $site->id;
            $site->delete();

            return [
                'ok' => true,
                'status' => 200,
                'deleted' => [
                    'site_id' => $siteId,
                    'paths' => $deletedPaths,
                    'tags' => $deletedTags,
                    'script_requests' => $deletedRequests,
                ],
            ];
        });
    }
}
