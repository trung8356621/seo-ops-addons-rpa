<?php

declare(strict_types=1);

/**
 * READ-ONLY post-fix eligibility check for campaign 13f41a46-...
 * Uses LIVE rows + new AgentKindPolicy/EligibilitySelector. NO dispatch/claim/status writes.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountIdentityKey;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;

$conn = 'omi_social_automation';
$db = DB::connection($conn);
$now = now();
$online = new OnlineStateCalculator;
$kinds = new AgentKindPolicy;
$selector = new EligibilitySelector(new AccountDeduplicator, $kinds);
$locks = new AccountLockPolicy;

$campaignUuid = '13f41a46-aefb-4343-b495-3ef99b5176d6';
$accountUuid = 'ff0035e0-a6d8-42af-8370-f5be9e453f2d';

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$account = $db->table('sa_accounts')->where('account_uuid', $accountUuid)->first();
$content = $campaign
    ? $db->table('sa_campaign_contents')->where('campaign_id', $campaign->id)->orderBy('id')->first()
    : null;

$out = [
    'probe' => 'client_dispatch_eligibility_ro',
    'now' => $now->toIso8601String(),
    'mutated' => false,
];

if (! $campaign || ! $account || ! $content) {
    $out['error'] = 'missing_rows';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$requiredAccountId = (int) ($content->assigned_account_id ?: $account->id);

$agents = $db->table('sa_agents')
    ->where('installation_id', $campaign->installation_id)
    ->get()
    ->map(function ($a) use ($online, $kinds, $now): array {
        $kind = $kinds->normalize(isset($a->agent_kind) ? (string) $a->agent_kind : null);
        $hb = $a->last_heartbeat_at ? new DateTimeImmutable((string) $a->last_heartbeat_at) : null;
        $hbOnline = $online->isOnline($hb, $now->toDateTimeImmutable());

        return [
            'agent_id' => (int) $a->id,
            'online' => $hbOnline,
            'availability' => (string) $a->availability,
            'active_jobs' => (int) $a->active_jobs,
            'max_concurrency' => max(1, (int) $a->max_concurrency),
            'agent_kind' => $kind,
            'dispatch_eligible' => $kinds->countsAsOnlineForDispatch($kind, $hbOnline),
            'last_heartbeat_at' => $a->last_heartbeat_at,
            'heartbeat_age_seconds' => $a->last_heartbeat_at
                ? ($now->getTimestamp() - strtotime((string) $a->last_heartbeat_at))
                : null,
        ];
    })->all();

$busyAccountIds = $db->table('sa_jobs')
    ->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)
    ->whereNotNull('account_id')
    ->pluck('account_id')
    ->all();

$sessions = [];
$browsers = $db->table('sa_browser_sessions')->get();
foreach ($browsers as $session) {
    $agent = null;
    foreach ($agents as $a) {
        if ((int) $a['agent_id'] === (int) $session->agent_id) {
            $agent = $a;
            break;
        }
    }
    if ($agent === null) {
        continue;
    }
    if (! (bool) ($session->dispatch_enabled ?? true)
        || ! in_array((string) ($session->session_state ?? 'candidate'), ['candidate', 'verified'], true)) {
        continue;
    }
    $activeBind = $db->table('sa_session_accounts')
        ->where('browser_session_id', $session->id)
        ->where('is_active', 1)
        ->first();
    $accountId = $activeBind->account_id ?? $session->account_id;
    if ($accountId === null || (int) $accountId <= 0) {
        continue;
    }
    $acc = $db->table('sa_accounts')->find($accountId);
    if (! $acc) {
        continue;
    }
    if ((string) $acc->installation_id !== (string) $campaign->installation_id) {
        continue;
    }
    if (strcasecmp((string) $acc->platform, (string) $campaign->platform) !== 0) {
        continue;
    }
    if ((int) $accountId !== $requiredAccountId) {
        continue;
    }
    $busy = $session->locked_job_id !== null
        && ($session->lock_expires_at === null || strtotime((string) $session->lock_expires_at) > $now->getTimestamp());
    $sessions[] = [
        'session_id' => (int) $session->id,
        'agent_id' => (int) $session->agent_id,
        'ready_status' => $session->ready_status,
        'busy' => $busy,
        'account_locked' => $locks->isAccountBusy((int) $accountId, $busyAccountIds),
        'platform' => $acc->platform,
        'domain' => $acc->domain,
        'account' => [
            'platform' => $acc->platform,
            'domain' => $acc->domain,
            'platform_account_id' => $acc->platform_account_id,
            'verified' => (bool) $acc->verified,
            'identity_source' => $acc->identity_source,
        ],
        'account_id' => (int) $accountId,
    ];
}

$selection = $selector->select($agents, $sessions, [
    'platform' => $campaign->platform,
    'target_count' => 1,
    'require_strong_identity' => false,
]);

$client = collect($agents)->firstWhere('agent_kind', 'client');

$out['campaign'] = [
    'id' => (int) $campaign->id,
    'uuid' => $campaign->campaign_uuid,
    'status' => $campaign->status,
    'platform' => $campaign->platform,
    'resource_recheck_seconds' => (int) $campaign->resource_recheck_seconds,
    'next_recheck_at' => $campaign->next_recheck_at,
    'next_recheck_due' => $campaign->next_recheck_at === null
        || strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp(),
    'meta' => json_decode((string) ($campaign->meta ?? '{}'), true),
];
$out['content'] = [
    'id' => (int) $content->id,
    'status' => $content->status,
    'assigned_account_id' => $content->assigned_account_id,
    'last_error_code' => $content->last_error_code,
];
$out['pinned_account'] = [
    'id' => (int) $account->id,
    'uuid' => $account->account_uuid,
    'identity_source' => $account->identity_source,
    'platform_account_id' => $account->platform_account_id,
    'strong_key' => AccountIdentityKey::strongKey([
        'platform' => $account->platform,
        'platform_account_id' => $account->platform_account_id,
        'verified' => (bool) $account->verified,
        'identity_source' => $account->identity_source,
    ]),
];
$out['client_agent'] = $client;
$out['sessions_for_pin'] = $sessions;
$out['selection'] = [
    'selected_count' => count($selection['selected']),
    'skip_reasons' => $selection['skip_reasons'],
    'selected_account_ids' => array_map(
        static fn ($row) => $row['account_id'] ?? null,
        $selection['selected']
    ),
];
$out['verdict'] = [
    'dispatch_eligible_client' => (bool) ($client['dispatch_eligible'] ?? false),
    'hard_pin_would_select' => count($selection['selected']) === 1
        && (int) ($selection['selected'][0]['account_id'] ?? 0) === $requiredAccountId,
    'would_silent_fallback' => false,
    'note' => 'RO simulation only — CampaignOrchestrator::dispatchCampaign NOT invoked',
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
