<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;

$db = DB::connection('omi_social_automation');

$agents = $db->table('sa_agents')->whereIn('id', [2, 5])->get();
$out = ['mutated' => false, 'agents' => []];
foreach ($agents as $a) {
    $activeJobs = $db->table('sa_jobs')
        ->where('agent_id', $a->id)
        ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
        ->get(['id', 'job_uuid', 'status', 'campaign_id', 'lease_expires_at', 'reserved_at', 'claimed_at']);
    $sessionLocks = $db->table('sa_browser_sessions')
        ->where('agent_id', $a->id)
        ->whereNotNull('locked_job_id')
        ->get(['id', 'session_uuid', 'locked_job_id', 'lock_expires_at']);
    $out['agents'][] = [
        'agent_id' => (int) $a->id,
        'client_api_key_id' => $a->client_api_key_id,
        'active_jobs_counter' => (int) $a->active_jobs,
        'max_concurrency' => (int) $a->max_concurrency,
        'real_active_jobs' => $activeJobs,
        'session_locks' => $sessionLocks,
        'stale_capacity' => (int) $a->active_jobs > 0 && $activeJobs->isEmpty(),
    ];
}

// Historical: any session_accounts ever for agent 2 with sandbox platform?
$hist = $db->select("
    SELECT sa.id bind_id, sa.is_active, sa.account_id, acc.platform, acc.username,
           bs.id session_id, bs.agent_id, sa.created_at, sa.updated_at
    FROM sa_session_accounts sa
    JOIN sa_browser_sessions bs ON bs.id = sa.browser_session_id
    JOIN sa_accounts acc ON acc.id = sa.account_id
    WHERE bs.agent_id = 2
    ORDER BY sa.id
");
$out['agent2_all_account_binds'] = $hist;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
