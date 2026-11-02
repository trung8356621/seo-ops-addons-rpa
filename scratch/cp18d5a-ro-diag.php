<?php

declare(strict_types=1);

/**
 * CP18D.5A READ-ONLY diagnosis. No mutations. No secrets printed.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$conn = 'omi_social_automation';
$out = static function (string $label, mixed $value): void {
    if (is_array($value) || is_object($value)) {
        echo $label.'='.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    } else {
        echo $label.'='.(string) $value.PHP_EOL;
    }
};

$out('HAS_SA_ACCOUNTS', Schema::connection($conn)->hasTable('sa_accounts') ? 'yes' : 'no');
$out('HAS_SA_BROWSER_SESSIONS', Schema::connection($conn)->hasTable('sa_browser_sessions') ? 'yes' : 'no');
$out('HAS_SA_SESSION_ACCOUNTS', Schema::connection($conn)->hasTable('sa_session_accounts') ? 'yes' : 'no');
$out('HAS_SA_AGENTS', Schema::connection($conn)->hasTable('sa_agents') ? 'yes' : 'no');

$accountCols = Schema::connection($conn)->getColumnListing('sa_accounts');
$out('ACCOUNT_COLUMNS', $accountCols);

$accounts = DB::connection($conn)->table('sa_accounts')
    ->where(function ($q): void {
        $q->where('name', 'like', '%mayhopphat%')
            ->orWhere('label', 'like', '%mayhopphat%')
            ->orWhere('username', 'like', '%mayhopphat%')
            ->orWhere('declared_username', 'like', '%mayhopphat%')
            ->orWhere('platform', 'like', '%mayhopphat%');
    })
    ->get([
        'id',
        'account_uuid',
        'installation_id',
        'platform',
        'name',
        'label',
        'username',
        'declared_username',
        'email',
        'declared_email',
        'site_url',
        'encrypted_password',
        'identity_source',
        'verified',
        'created_at',
        'updated_at',
    ]);

$out('ACCOUNT_MATCH_COUNT', $accounts->count());
foreach ($accounts as $a) {
    $safe = (array) $a;
    $enc = $safe['encrypted_password'] ?? null;
    unset($safe['encrypted_password']);
    $safe['has_password'] = is_string($enc) && $enc !== '';
    $safe['encrypted_password_present'] = $safe['has_password'];
    $out('ACCOUNT_ROW', $safe);
}

$accountIds = $accounts->pluck('id')->filter()->values()->all();
$accountUuids = $accounts->pluck('account_uuid')->filter()->values()->all();
$installationIds = $accounts->pluck('installation_id')->unique()->values()->all();

// Browser / session binding
$sessionAccountCols = Schema::connection($conn)->hasTable('sa_session_accounts')
    ? Schema::connection($conn)->getColumnListing('sa_session_accounts')
    : [];
$out('SESSION_ACCOUNT_COLUMNS', $sessionAccountCols);

$browserCols = Schema::connection($conn)->hasTable('sa_browser_sessions')
    ? Schema::connection($conn)->getColumnListing('sa_browser_sessions')
    : [];
$out('BROWSER_COLUMNS', $browserCols);

$bindings = collect();
if ($accountIds !== [] && Schema::connection($conn)->hasTable('sa_session_accounts')) {
    $bindings = DB::connection($conn)->table('sa_session_accounts')
        ->whereIn('account_id', $accountIds)
        ->orderByDesc('id')
        ->get();
}
$out('SESSION_ACCOUNT_BINDINGS', $bindings);

$browserIds = $bindings->pluck('browser_session_id')->filter()->unique()->values()->all();

// Also find browsers that reference account_id directly if column exists
$directBrowsers = collect();
if ($accountIds !== [] && in_array('account_id', $browserCols, true)) {
    $directBrowsers = DB::connection($conn)->table('sa_browser_sessions')
        ->whereIn('account_id', $accountIds)
        ->orderByDesc('id')
        ->get();
}
$out('DIRECT_BROWSER_BY_ACCOUNT_ID_COUNT', $directBrowsers->count());

$browserSelect = array_values(array_intersect($browserCols, [
    'id',
    'browser_uuid',
    'browser_id',
    'session_uuid',
    'installation_id',
    'agent_id',
    'account_id',
    'platform',
    'label',
    'display_name',
    'session_state',
    'online_state',
    'signal_state',
    'ready_state',
    'confirmed_at',
    'last_confirmed_at',
    'last_heartbeat_at',
    'last_seen_at',
    'heartbeat_at',
    'updated_at',
    'created_at',
    'is_online',
    'status',
]));

$browsers = collect();
if ($browserIds !== []) {
    $browsers = DB::connection($conn)->table('sa_browser_sessions')
        ->whereIn('id', $browserIds)
        ->get($browserSelect ?: ['*']);
}
if ($directBrowsers->isNotEmpty()) {
    $browsers = $browsers->concat($directBrowsers)->unique('id')->values();
}

// Fallback: browsers in same installation(s)
if ($browsers->isEmpty() && $installationIds !== []) {
    $fallback = DB::connection($conn)->table('sa_browser_sessions')
        ->whereIn('installation_id', $installationIds)
        ->orderByDesc('updated_at')
        ->limit(20)
        ->get($browserSelect ?: ['*']);
    $out('BROWSER_INSTALLATION_FALLBACK_COUNT', $fallback->count());
    foreach ($fallback as $b) {
        $out('BROWSER_INSTALLATION_ROW', $b);
    }
} else {
    $out('BROWSER_BOUND_COUNT', $browsers->count());
    foreach ($browsers as $b) {
        $out('BROWSER_ROW', $b);
    }
}

// Agents for ownership
$agentIds = $browsers->pluck('agent_id')->filter()->unique()->values()->all();
if ($agentIds !== [] && Schema::connection($conn)->hasTable('sa_agents')) {
    $agentCols = Schema::connection($conn)->getColumnListing('sa_agents');
    $agentSelect = array_values(array_intersect($agentCols, [
        'id',
        'agent_uuid',
        'installation_id',
        'kind',
        'name',
        'label',
        'last_heartbeat_at',
        'last_seen_at',
        'online_state',
        'status',
        'updated_at',
    ]));
    $agents = DB::connection($conn)->table('sa_agents')->whereIn('id', $agentIds)->get($agentSelect ?: ['id']);
    foreach ($agents as $ag) {
        $out('AGENT_ROW', $ag);
    }
}

$out('NOW_UTC', gmdate('c'));
$out('DONE', 'ok');
