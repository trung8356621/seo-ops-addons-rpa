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

if (! $user) {
    fwrite(STDERR, "NO_STAFF\n");
    exit(2);
}

function hit($kernel, $user, string $method, string $path, bool $auth): array
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
        'https://seo-ops.duckdns.org'.$path,
        $method,
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

$anon = hit($kernel, $user, 'GET', '/social-automation/api/script-registry', false);
$authList = hit($kernel, $user, 'GET', '/social-automation/api/script-registry', true);
$missing = hit($kernel, $user, 'GET', '/social-automation/api/script-registry/__cp12a_missing_pkg__', true);

echo 'ANON_LIST_STATUS='.$anon['status'].PHP_EOL;
echo 'AUTH_LIST_STATUS='.$authList['status'].PHP_EOL;
echo 'AUTH_LIST_HEAD='.substr($authList['body'], 0, 240).PHP_EOL;
echo 'MISSING_STATUS='.$missing['status'].PHP_EOL;
echo 'MISSING_BODY='.substr($missing['body'], 0, 160).PHP_EOL;

$listJson = json_decode($authList['body'], true);
echo 'LIST_OK='.(($listJson['ok'] ?? false) ? 'yes' : 'no').PHP_EOL;
echo 'LIST_COUNT='.(string) ($listJson['count'] ?? 'n/a').PHP_EOL;
echo 'LIST_IS_ARRAY='.(isset($listJson['scripts']) && is_array($listJson['scripts']) ? 'yes' : 'no').PHP_EOL;
