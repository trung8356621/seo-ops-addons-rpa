<?php

declare(strict_types=1);

/**
 * CP17B.8 — RO bootstrap verify. Prints statuses + role field presence only.
 * Does not print cookies, tokens, passwords, or full bootstrap secrets.
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$host = 'seo-ops.duckdns.org';
$path = '/api/social-automation/bootstrap';

$server = [
    'HTTP_HOST' => $host,
    'HTTPS' => 'on',
    'SERVER_PORT' => '443',
    'REQUEST_SCHEME' => 'https',
    'HTTP_ACCEPT' => 'application/json',
];

// --- Unauthenticated ---
$reqUnauth = Request::create('https://'.$host.$path, 'GET', [], [], [], $server);
$resUnauth = $kernel->handle($reqUnauth);
$unauthStatus = $resUnauth->getStatusCode();
$kernel->terminate($reqUnauth, $resUnauth);
echo 'BOOTSTRAP_UNAUTH_STATUS='.$unauthStatus.PHP_EOL;

// --- Authenticated (staff preferred) ---
$user = User::query()
    ->where('role', User::ROLE_STAFF)
    ->where('status', '!=', User::STATUS_BLOCK)
    ->orderBy('id')
    ->first();

if (! $user) {
    $user = User::query()
        ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_OWNER])
        ->where('status', '!=', User::STATUS_BLOCK)
        ->orderBy('id')
        ->first();
}

if (! $user) {
    echo "BOOTSTRAP_AUTH_STATUS=UNAVAILABLE\n";
    echo "ROLE_FIELD_PRESENT=unknown\n";
    echo "AUTH_USER_ROLE=none\n";
    echo "BINDING_RESOLUTION_EXCEPTION=unknown\n";
    exit(2);
}

Auth::login($user);
if (method_exists($user, 'getAuthPassword')) {
    session()->put('password_hash_web', $user->getAuthPassword());
}
session()->save();

$cookieName = (string) config('session.cookie');
$sessionId = session()->getId();

$reqAuth = Request::create(
    'https://'.$host.$path,
    'GET',
    [],
    [$cookieName => $sessionId],
    [],
    $server
);
$resAuth = $kernel->handle($reqAuth);
$authStatus = $resAuth->getStatusCode();
$body = (string) $resAuth->getContent();
$kernel->terminate($reqAuth, $resAuth);

echo 'BOOTSTRAP_AUTH_STATUS='.$authStatus.PHP_EOL;
echo 'AUTH_USER_ID='.(int) $user->id.PHP_EOL;
echo 'AUTH_USER_ROLE='.(string) $user->role.PHP_EOL;

$hasBinding = str_contains($body, 'BindingResolutionException')
    || str_contains($body, 'SocialAutomationServiceHealth] does not exist');
echo 'BINDING_RESOLUTION_EXCEPTION='.($hasBinding ? 'yes' : 'no').PHP_EOL;

$json = json_decode($body, true);
$userPayload = is_array($json) ? ($json['bootstrap']['user'] ?? null) : null;
$rolePresent = is_array($userPayload)
    && array_key_exists('id', $userPayload)
    && array_key_exists('name', $userPayload)
    && array_key_exists('role', $userPayload);
echo 'ROLE_FIELD_PRESENT='.($rolePresent ? 'yes' : 'no').PHP_EOL;
echo 'BOOTSTRAP_OK_FLAG='.(is_array($json) && ($json['ok'] ?? false) === true ? 'yes' : 'no').PHP_EOL;

if (is_array($userPayload)) {
    echo 'USER_KEYS='.implode(',', array_keys($userPayload)).PHP_EOL;
    echo 'USER_ROLE_VALUE='.(string) ($userPayload['role'] ?? '').PHP_EOL;
    // do not print name (PII) — only length
    echo 'USER_NAME_LEN='.strlen((string) ($userPayload['name'] ?? '')).PHP_EOL;
    echo 'USER_ID_MATCH='.(((int) ($userPayload['id'] ?? 0)) === (int) $user->id ? 'yes' : 'no').PHP_EOL;
}

if ($authStatus >= 500) {
    // safe snippet only
    $snip = preg_replace('/\s+/', ' ', substr($body, 0, 240));
    echo 'ERROR_SNIP='.$snip.PHP_EOL;
}
