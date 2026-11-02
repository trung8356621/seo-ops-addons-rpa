<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;

$conn = 'omi_social_automation';

$session = DB::connection($conn)->table('sa_browser_sessions')->where('id', 1)->first();
$agent = DB::connection($conn)->table('sa_agents')->where('id', 1)->first();

echo 'BROWSER_FULL='.json_encode([
    'id' => $session->id ?? null,
    'session_uuid' => $session->session_uuid ?? null,
    'agent_id' => $session->agent_id ?? null,
    'account_id' => $session->account_id ?? null,
    'platform' => $session->platform ?? null,
    'domain' => $session->domain ?? null,
    'ready_status' => $session->ready_status ?? null,
    'session_state' => $session->session_state ?? null,
    'dispatch_enabled' => $session->dispatch_enabled ?? null,
    'last_confirmed_at' => $session->last_confirmed_at ?? null,
    'updated_at' => $session->updated_at ?? null,
    'capabilities' => isset($session->capabilities) ? json_decode((string) $session->capabilities, true) : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

echo 'AGENT_FULL='.json_encode([
    'id' => $agent->id ?? null,
    'agent_uuid' => $agent->agent_uuid ?? null,
    'installation_id' => $agent->installation_id ?? null,
    'agent_kind' => $agent->agent_kind ?? null,
    'last_heartbeat_at' => $agent->last_heartbeat_at ?? null,
    'updated_at' => $agent->updated_at ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

$calc = new OnlineStateCalculator;
$hb = isset($agent->last_heartbeat_at) ? new DateTimeImmutable((string) $agent->last_heartbeat_at) : null;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
echo 'HEARTBEAT_TTL_SECONDS='.$calc->ttlSeconds().PHP_EOL;
echo 'DEVICE_ONLINE='.($calc->isOnline($hb, $now) ? 'true' : 'false').PHP_EOL;
echo 'HEARTBEAT_AGE_SECONDS='.($hb ? ($now->getTimestamp() - $hb->getTimestamp()) : 'null').PHP_EOL;
echo 'NOW_UTC='.$now->format(DateTimeInterface::ATOM).PHP_EOL;

$sync = new SessionSyncService($calc);
$modelSession = SaBrowserSession::query()->find(1);
$modelAgent = SaAgent::query()->find(1);
if ($modelSession instanceof SaBrowserSession) {
    $row = $sync->mapStaffBrowserRow($modelSession, $modelAgent instanceof SaAgent ? $modelAgent : null, null, $now);
    echo 'STAFF_BROWSER_ROW='.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
}

$inventory = $sync->staffBrowserInventory('ins_4Zn6M7cHZIQI2UbS');
$summary = $inventory['summary'] ?? null;
$browsers = $inventory['browsers'] ?? [];
echo 'STAFF_SUMMARY='.json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'STAFF_STATUS_POLICY='.json_encode($inventory['status_policy'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
foreach ($browsers as $b) {
    echo 'STAFF_BROWSER='.json_encode([
        'browser_id' => $b['browser_id'] ?? null,
        'is_online' => $b['is_online'] ?? null,
        'device_online' => $b['device_online'] ?? null,
        'runtime_status' => $b['runtime_status'] ?? null,
        'reported_status' => $b['reported_status'] ?? null,
        'signal_status' => $b['signal_status'] ?? null,
        'username' => $b['username'] ?? null,
        'account_name' => $b['account_name'] ?? null,
        'device_heartbeat_at' => $b['device_heartbeat_at'] ?? null,
        'last_seen_at' => $b['last_seen_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
}

// Simulate Client list/detail field projection from DB (no auth, no secrets)
$account = DB::connection($conn)->table('sa_accounts')->where('id', 1)->first();
$listProjection = [
    'account_id' => (string) ($account->account_uuid ?? $account->id),
    'platform' => strtolower((string) $account->platform),
    'display_name' => (string) ($account->name ?? $account->label ?? $account->username ?? 'Account'),
    'username' => $account->username ?? $account->declared_username,
    'email' => $account->email ?? $account->declared_email,
    'has_password' => is_string($account->encrypted_password ?? null) && $account->encrypted_password !== '',
];
$detailProjection = [
    'account_id' => (string) ($account->account_uuid ?? $account->id),
    'platform' => strtolower((string) $account->platform),
    'display_name' => (string) ($account->name ?? $account->label ?? ''),
    'email' => $account->email ?? $account->declared_email,
    // NOTE: getAccount() currently omits username
    'password_status' => (is_string($account->encrypted_password ?? null) && $account->encrypted_password !== '') ? 'available' : 'not_set',
];
echo 'CLIENT_LIST_PROJECTION='.json_encode($listProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'CLIENT_DETAIL_PROJECTION='.json_encode($detailProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'PASSWORD_ENDPOINT_INCLUDES_USERNAME=yes'.PHP_EOL;
