<?php

declare(strict_types=1);

/**
 * CP11.3 Tag create/delete retest via Staff Session HTTP kernel.
 * Single create, single delete. No retries. No Site/Path mutation.
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function staffUser(): User
{
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
        throw new RuntimeException('NO_STAFF_USER');
    }

    return $user;
}

function authedRequest(Illuminate\Contracts\Http\Kernel $kernel, string $method, string $path, array $payload = [], ?string $csrf = null): array
{
    $user = staffUser();
    Auth::login($user);
    if (method_exists($user, 'getAuthPassword')) {
        session()->put('password_hash_web', $user->getAuthPassword());
    }
    session()->save();

    $cookieName = config('session.cookie');
    $sessionId = session()->getId();
    $cookies = [$cookieName => $sessionId];
    if ($csrf) {
        $cookies['XSRF-TOKEN'] = $csrf;
    }

    $server = [
        'HTTP_HOST' => 'seo-ops.duckdns.org',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
        'REQUEST_SCHEME' => 'https',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ];
    if ($csrf) {
        $server['HTTP_X_XSRF_TOKEN'] = $csrf;
        $server['HTTP_X_CSRF_TOKEN'] = $csrf;
    }

    $request = Request::create(
        'https://seo-ops.duckdns.org'.$path,
        $method,
        $payload,
        $cookies,
        [],
        $server,
        $method === 'GET' ? null : json_encode($payload, JSON_UNESCAPED_UNICODE)
    );
    if ($method !== 'GET') {
        $request->headers->set('Content-Type', 'application/json');
    }

    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    $kernel->terminate($request, $response);

    return [
        'status' => $status,
        'body' => $body,
        'json' => json_decode($body, true),
        'session' => $sessionId,
        'user_id' => $user->id,
        'role' => $user->role,
    ];
}

$matrix = [];

// 1) GET /data
$data = authedRequest($kernel, 'GET', '/social-automation/api/action-registry/data');
$matrix['GET /data'] = $data['status'];
echo 'STAFF_AUTH user_id='.$data['user_id'].' role='.$data['role'].' data_status='.$data['status'].PHP_EOL;

if ($data['status'] !== 200) {
    echo 'ERRORS=GET_/data_failed'.PHP_EOL;
    echo 'HTTP_STATUS_MATRIX='.json_encode($matrix).PHP_EOL;
    exit(1);
}

$json = $data['json'] ?? [];
$sites = collect($json['sites'] ?? $json['data']['sites'] ?? []);
$paths = collect($json['paths'] ?? $json['data']['paths'] ?? []);
$tags = collect($json['tags'] ?? $json['data']['tags'] ?? []);
$actions = collect($json['actions'] ?? $json['data']['actions'] ?? []);

// Dump top-level keys for debugging shape
echo 'DATA_KEYS='.implode(',', array_keys($json)).PHP_EOL;
if (isset($json['data']) && is_array($json['data'])) {
    echo 'DATA_DATA_KEYS='.implode(',', array_keys($json['data'])).PHP_EOL;
}

$site4 = $sites->first(fn ($s) => (int) ($s['id'] ?? 0) === 4);
$path5 = $paths->first(fn ($p) => (int) ($p['id'] ?? 0) === 5);
$dup = $tags->first(fn ($t) => (int) ($t['site_id'] ?? 0) === 4 && (int) ($t['path_id'] ?? 0) === 5);

echo 'SITE4='.($site4 ? 'yes key='.($site4['key'] ?? '') : 'NO').PHP_EOL;
echo 'PATH5='.($path5 ? 'yes key='.($path5['key'] ?? '') : 'NO').PHP_EOL;
echo 'EXISTING_TAGS_SITE4_PATH5='.($dup ? 'YES id='.($dup['id'] ?? '').' key='.($dup['tag_key'] ?? '') : 'none').PHP_EOL;

if (! $site4 || ! $path5) {
    echo 'ERRORS=missing_site_or_path'.PHP_EOL;
    echo 'HTTP_STATUS_MATRIX='.json_encode($matrix).PHP_EOL;
    exit(1);
}
if ($dup) {
    echo 'ERRORS=duplicate_test_tag_exists'.PHP_EOL;
    echo 'HTTP_STATUS_MATRIX='.json_encode($matrix).PHP_EOL;
    exit(1);
}

$action = $actions->first(fn ($a) => ($a['key'] ?? '') === 'post') ?: $actions->first();
$actionKey = (string) ($action['key'] ?? 'post');

// Need CSRF for mutating requests — pull from cookie jar after a GET that sets XSRF-TOKEN
$user = staffUser();
Auth::login($user);
if (method_exists($user, 'getAuthPassword')) {
    session()->put('password_hash_web', $user->getAuthPassword());
}
$token = csrf_token();
session()->save();
$cookieName = config('session.cookie');
$sessionId = session()->getId();

$payload = [
    'site_id' => 4,
    'path_id' => 5,
    'action_key' => $actionKey,
];

$createReq = Request::create(
    'https://seo-ops.duckdns.org/social-automation/api/action-registry/tags',
    'POST',
    [],
    [$cookieName => $sessionId, 'XSRF-TOKEN' => $token],
    [],
    [
        'HTTP_HOST' => 'seo-ops.duckdns.org',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
        'REQUEST_SCHEME' => 'https',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_X_CSRF_TOKEN' => $token,
        'HTTP_X_XSRF_TOKEN' => $token,
        'CONTENT_TYPE' => 'application/json',
    ],
    json_encode($payload, JSON_UNESCAPED_UNICODE)
);
$createReq->headers->set('Content-Type', 'application/json');
$createRes = $kernel->handle($createReq);
$createStatus = $createRes->getStatusCode();
$createBody = (string) $createRes->getContent();
$kernel->terminate($createReq, $createRes);
$matrix['POST /tags'] = $createStatus;
$createJson = json_decode($createBody, true);

echo 'POST_STATUS='.$createStatus.PHP_EOL;
echo 'POST_BODY_HEAD='.Str::limit($createBody, 500).PHP_EOL;

$tagId = $createJson['tag']['id'] ?? $createJson['data']['tag']['id'] ?? null;
$tagKey = $createJson['tag']['tag_key'] ?? $createJson['data']['tag']['tag_key'] ?? null;
$reqId = $createJson['request']['id'] ?? $createJson['request']['request_id'] ?? null;

echo 'TAG_CREATED_ID='.($tagId ?? 'null').PHP_EOL;
echo 'TAG_KEY='.($tagKey ?? 'null').PHP_EOL;
echo 'SCRIPT_REQUEST='.json_encode($createJson['request'] ?? null).PHP_EOL;

if ($createStatus < 200 || $createStatus >= 300 || ! $tagId) {
    echo 'ERRORS=create_failed_no_retry'.PHP_EOL;
    echo 'HTTP_STATUS_MATRIX='.json_encode($matrix).PHP_EOL;
    exit(1);
}

// Verify appears in GET /data
$after = authedRequest($kernel, 'GET', '/social-automation/api/action-registry/data');
$matrix['GET /data after create'] = $after['status'];
$afterTags = collect(($after['json']['tags'] ?? $after['json']['data']['tags'] ?? []));
$found = $afterTags->first(fn ($t) => (int) ($t['id'] ?? 0) === (int) $tagId);
echo 'TAG_IN_API_AFTER_CREATE='.($found ? 'yes' : 'NO').PHP_EOL;

// Delete once
Auth::login($user);
if (method_exists($user, 'getAuthPassword')) {
    session()->put('password_hash_web', $user->getAuthPassword());
}
$token2 = csrf_token();
session()->save();
$sessionId2 = session()->getId();

$delReq = Request::create(
    'https://seo-ops.duckdns.org/social-automation/api/action-registry/tags/'.$tagId,
    'DELETE',
    [],
    [$cookieName => $sessionId2, 'XSRF-TOKEN' => $token2],
    [],
    [
        'HTTP_HOST' => 'seo-ops.duckdns.org',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
        'REQUEST_SCHEME' => 'https',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_X_CSRF_TOKEN' => $token2,
        'HTTP_X_XSRF_TOKEN' => $token2,
    ]
);
$delRes = $kernel->handle($delReq);
$delStatus = $delRes->getStatusCode();
$delBody = (string) $delRes->getContent();
$kernel->terminate($delReq, $delRes);
$matrix['DELETE /tags/{id}'] = $delStatus;
echo 'DELETE_STATUS='.$delStatus.PHP_EOL;
echo 'DELETE_BODY_HEAD='.Str::limit($delBody, 300).PHP_EOL;

$final = authedRequest($kernel, 'GET', '/social-automation/api/action-registry/data');
$matrix['GET /data after delete'] = $final['status'];
$finalTags = collect(($final['json']['tags'] ?? $final['json']['data']['tags'] ?? []));
$still = $finalTags->first(fn ($t) => (int) ($t['id'] ?? 0) === (int) $tagId);
$remainSite4 = $finalTags->filter(fn ($t) => (int) ($t['site_id'] ?? 0) === 4 && (int) ($t['path_id'] ?? 0) === 5)->values();

echo 'TAG_GONE='.($still ? 'NO' : 'yes').PHP_EOL;
echo 'REMAINING_SITE4_PATH5='.json_encode($remainSite4).PHP_EOL;
echo 'HTTP_STATUS_MATRIX='.json_encode($matrix).PHP_EOL;
echo 'TAG_DELETE_VERIFIED='.(($delStatus >= 200 && $delStatus < 300 && ! $still) ? 'YES' : 'NO').PHP_EOL;
echo 'UI_PATH=API_KERNEL_ONLY_browser_login_required_for_UI'.PHP_EOL;
