<?php

declare(strict_types=1);

/**
 * CP11.3 — mint a short-lived Staff web session cookie (no password print).
 * Prints only cookie name + value + user id/role for browser injection.
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
    fwrite(STDERR, "NO_STAFF_USER\n");
    exit(2);
}

Auth::login($user);
if (method_exists($user, 'getAuthPassword')) {
    session()->put('password_hash_web', $user->getAuthPassword());
}
session()->save();

$cookieName = config('session.cookie');
$sessionId = session()->getId();

$request = Request::create(
    'https://seo-ops.duckdns.org/social-automation/api/action-registry/data',
    'GET',
    [],
    [$cookieName => $sessionId],
    [],
    [
        'HTTP_HOST' => 'seo-ops.duckdns.org',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
        'REQUEST_SCHEME' => 'https',
        'HTTP_ACCEPT' => 'application/json',
    ]
);
$response = $kernel->handle($request);
$status = $response->getStatusCode();
$body = (string) $response->getContent();
$kernel->terminate($request, $response);

echo 'user_id='.$user->id.PHP_EOL;
echo 'role='.$user->role.PHP_EOL;
echo 'cookie_name='.$cookieName.PHP_EOL;
echo 'cookie_value='.$sessionId.PHP_EOL;
echo 'data_status='.$status.PHP_EOL;
echo 'data_ok='.(str_contains($body, '"ok":true') || str_contains($body, '"ok": true') ? 'yes' : 'no').PHP_EOL;
echo 'secure='.(config('session.secure') ? '1' : '0').PHP_EOL;
echo 'same_site='.(string) config('session.same_site').PHP_EOL;
echo 'domain='.(string) (config('session.domain') ?? '').PHP_EOL;
