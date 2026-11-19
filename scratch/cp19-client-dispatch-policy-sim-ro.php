<?php

declare(strict_types=1);

/** READ-ONLY: policy sim with LIVE rows; optional force_online=1 query via argv. No writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\AccountDeduplicator;
use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\EligibilitySelector;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;

$forceOnline = in_array('force_online=1', $argv, true);
$db = DB::connection('omi_social_automation');
$now = now();
$online = new OnlineStateCalculator;
$kinds = new AgentKindPolicy;
$selector = new EligibilitySelector(new AccountDeduplicator, $kinds);
$locks = new AccountLockPolicy;

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', '13f41a46-aefb-4343-b495-3ef99b5176d6')->first();
$account = $db->table('sa_accounts')->where('account_uuid', 'ff0035e0-a6d8-42af-8370-f5be9e453f2d')->first();
$content = $db->table('sa_campaign_contents')->where('campaign_id', $campaign->id)->first();
$agent = $db->table('sa_agents')->where('installation_id', $campaign->installation_id)->where('agent_kind', 'client')->first();
$session = $db->table('sa_browser_sessions')->where('account_id', $account->id)->first();

$hb = $agent->last_heartbeat_at ? new DateTimeImmutable((string) $agent->last_heartbeat_at) : null;
$hbOnline = $forceOnline ? true : $online->isOnline($hb, $now->toDateTimeImmutable());
$kind = $kinds->normalize((string) $agent->agent_kind);

$agents = [[
    'agent_id' => (int) $agent->id,
    'online' => $hbOnline,
    'availability' => (string) $agent->availability,
    'active_jobs' => (int) $agent->active_jobs,
    'max_concurrency' => max(1, (int) $agent->max_concurrency),
    'agent_kind' => $kind,
]];

$busyIds = $db->table('sa_jobs')->whereIn('status', AccountLockPolicy::ACTIVE_STATUSES)->whereNotNull('account_id')->pluck('account_id')->all();

$sessions = [[
    'session_id' => (int) $session->id,
    'agent_id' => (int) $session->agent_id,
    'ready_status' => $session->ready_status,
    'busy' => false,
    'account_locked' => $locks->isAccountBusy((int) $account->id, $busyIds),
    'platform' => $account->platform,
    'account' => [
        'platform' => $account->platform,
        'platform_account_id' => $account->platform_account_id,
        'verified' => (bool) $account->verified,
        'identity_source' => $account->identity_source,
    ],
    'account_id' => (int) $account->id,
]];

$sel = $selector->select($agents, $sessions, [
    'platform' => $campaign->platform,
    'target_count' => 1,
    'require_strong_identity' => false,
]);

echo json_encode([
    'force_online' => $forceOnline,
    'hb_age_seconds' => $agent->last_heartbeat_at ? ($now->getTimestamp() - strtotime((string) $agent->last_heartbeat_at)) : null,
    'dispatch_eligible' => $kinds->countsAsOnlineForDispatch($kind, $hbOnline),
    'selected_count' => count($sel['selected']),
    'selected_account_id' => $sel['selected'][0]['account_id'] ?? null,
    'skip_reasons' => $sel['skip_reasons'],
    'campaign_status' => $campaign->status,
    'content_status' => $content->status,
    'next_recheck_at' => $campaign->next_recheck_at,
    'next_recheck_due' => $campaign->next_recheck_at === null || strtotime((string) $campaign->next_recheck_at) <= $now->getTimestamp(),
    'policy_file' => realpath('/www/wwwroot/seo-ops-addons/social-automation/src/Domain/AgentKindPolicy.php'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
