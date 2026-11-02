<?php

declare(strict_types=1);

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
    ->first()
    ?: User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_OWNER])->orderBy('id')->first();

function hit($kernel, $user, bool $auth): array
{
    if ($auth) {
        Auth::login($user);
        if (method_exists($user, 'getAuthPassword')) {
            session()->put('password_hash_web', $user->getAuthPassword());
        }
        session()->save();
        $cookies = [config('session.cookie') => session()->getId()];
    } else {
        Auth::logout();
        session()->flush();
        $cookies = [];
    }

    $request = Request::create(
        'https://seo-ops.duckdns.org/social-automation/api/browsers',
        'GET',
        [],
        $cookies,
        [],
        [
            'HTTP_HOST' => 'seo-ops.duckdns.org',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REQUEST_SCHEME' => 'https',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]
    );
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    $kernel->terminate($request, $response);

    return ['status' => $status, 'body' => $body];
}

$anon = hit($kernel, $user, false);
$auth = hit($kernel, $user, true);
echo 'ANON_GET_STATUS='.$anon['status'].PHP_EOL;
echo 'STAFF_GET_STATUS='.$auth['status'].PHP_EOL;
echo 'STAFF_BODY_HEAD='.substr($auth['body'], 0, 300).PHP_EOL;
$j = json_decode($auth['body'], true);
echo 'HAS_BROWSERS='.(isset($j['browsers']) ? 'yes' : 'no').PHP_EOL;
echo 'HAS_SUMMARY='.(isset($j['summary']) ? 'yes' : 'no').PHP_EOL;
echo 'HAS_STATUS_POLICY='.(isset($j['status_policy']) ? 'yes' : 'no').PHP_EOL;
$enc = $auth['body'];
foreach (['raw_key', 'encrypted_key', 'sk_live_', 'password', 'connection_token'] as $bad) {
    if (stripos($enc, $bad) !== false) {
        echo 'SECRET_LEAK='.$bad.PHP_EOL;
    }
}
