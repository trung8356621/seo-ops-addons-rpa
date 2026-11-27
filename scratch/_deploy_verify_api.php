<?php

declare(strict_types=1);

/**
 * Non-destructive LIVE verification of Owner-assign Client Key deploy.
 * Does not mint credentials. Does not mutate campaigns/keys.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'TS='.gmdate('c').PHP_EOL;

// Source hash verify vs expected files present
$files = [
    'src/Domain/ClientApiKeyAssignmentPolicy.php',
    'src/Http/Controllers/RpaClientApiKeyManagementController.php',
    'src/Support/EloquentClientApiKeyAssigneeDirectory.php',
    'database/migrations/2026_11_25_130000_add_issued_by_to_sa_client_api_keys_table.php',
];
$root = '/www/wwwroot/seo-ops-addons/social-automation';
foreach ($files as $rel) {
    $p = $root.'/'.$rel;
    echo 'HASH '.$rel.'='.(is_file($p) ? hash_file('sha256', $p) : 'MISSING').PHP_EOL;
}

// Route presence
$routes = app('router')->getRoutes();
$want = [
    'social-automation.client-keys.store',
    'social-automation.client-keys.assignable-users',
    'social-automation.client-keys.index',
];
foreach ($want as $name) {
    $r = $routes->getByName($name);
    if (! $r) {
        echo "ROUTE_MISSING={$name}\n";
        continue;
    }
    echo 'ROUTE='.$name.'|uri='.$r->uri().'|methods='.implode(',', $r->methods()).'|action='.$r->getActionName().PHP_EOL;
}

// Reflect controller source contract markers
$ctrl = file_get_contents($root.'/src/Http/Controllers/RpaClientApiKeyManagementController.php');
foreach ([
    'assigned_to_user_id',
    'assignment_forbidden',
    'issued_by',
    'assignableUsers',
    'created_by',
    'raw_key',
] as $needle) {
    echo 'CTRL_HAS_'.$needle.'='.(str_contains($ctrl, $needle) ? 'yes' : 'no').PHP_EOL;
}

$policy = file_get_contents($root.'/src/Domain/ClientApiKeyAssignmentPolicy.php');
foreach (['ERROR_ASSIGNMENT_FORBIDDEN', 'ERROR_ASSIGNED_USER_INVALID', 'ERROR_ASSIGNED_USER_INELIGIBLE', 'resolveCreateOwnership'] as $needle) {
    echo 'POLICY_HAS_'.$needle.'='.(str_contains((string) $policy, $needle) ? 'yes' : 'no').PHP_EOL;
}

// OpenAPI markers
$oa = file_get_contents($root.'/openapi/openapi.yaml');
echo 'OA_ASSIGNABLE='.(str_contains((string) $oa, '/social-automation/api/client-keys/assignable-users') ? 'yes' : 'no').PHP_EOL;
echo 'OA_ASSIGNED_TO='.(str_contains((string) $oa, 'assigned_to_user_id') ? 'yes' : 'no').PHP_EOL;

// Schema + inventory preservation
$db = DB::connection('omi_social_automation');
$schema = $db->getSchemaBuilder();
echo 'HAS_ISSUED_BY='.($schema->hasColumn('sa_client_api_keys', 'issued_by') ? 'yes' : 'no').PHP_EOL;
$col = $db->select("SHOW COLUMNS FROM sa_client_api_keys LIKE 'issued_by'");
echo 'ISSUED_BY_NULLABLE='.(($col[0]->Null ?? '') === 'YES' ? 'yes' : 'no').PHP_EOL;

$keys = $db->table('sa_client_api_keys')->orderBy('id')->get(['id', 'name', 'created_by', 'issued_by', 'revoked_at']);
echo 'KEY_COUNT='.count($keys).PHP_EOL;
$qa = $keys->firstWhere('id', 10);
echo 'QA10='.json_encode($qa).PHP_EOL;

$agents = $db->table('sa_agents')->count();
echo 'AGENT_COUNT='.$agents.PHP_EOL;
$jobs = $db->table('sa_jobs')->select('status', DB::raw('count(*) as c'))->groupBy('status')->get();
echo 'JOBS='.json_encode($jobs).PHP_EOL;
$campaigns = $db->table('sa_campaigns')->select('status', DB::raw('count(*) as c'))->groupBy('status')->get();
echo 'CAMPAIGNS='.json_encode($campaigns).PHP_EOL;
$leases = $db->table('sa_jobs')->whereNotNull('lease_expires_at')->where('lease_expires_at', '>', now())->count();
echo 'ACTIVE_LEASES='.$leases.PHP_EOL;

// Unit-level policy smoke without minting (in-process)
$access = app(\Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess::class);
$access->setForcedActiveForTesting(true);
$policySvc = new \Omnichannel\Addons\SocialAutomation\Domain\ClientApiKeyAssignmentPolicy($access);

$owner = new App\Models\User;
$owner->id = 1;
$owner->role = App\Models\User::ROLE_OWNER;
$owner->status = App\Models\User::STATUS_NORMAL;

$staff = new App\Models\User;
$staff->id = 42;
$staff->role = App\Models\User::ROLE_STAFF;
$staff->status = App\Models\User::STATUS_NORMAL;
$staff->parent_id = 1;

$self = $policySvc->resolveCreateOwnership($owner, null);
echo 'POLICY_OWNER_SELF_OK='.(($self['ok'] ?? false) ? 'yes' : 'no').'|owner='.($self['owner_user_id'] ?? '').'|issued='.($self['issued_by_user_id'] ?? '').PHP_EOL;

$deny = $policySvc->resolveCreateOwnership($staff, 99);
echo 'POLICY_STAFF_ASSIGN_DENY='.(($deny['error'] ?? '') === 'assignment_forbidden' ? 'yes' : 'no').PHP_EOL;

echo "VERIFY_DONE=yes\n";
