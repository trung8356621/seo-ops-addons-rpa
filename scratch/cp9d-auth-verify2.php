<?php

declare(strict_types=1);

/**
 * CP9D authenticated Staff Session SPA verification (read-only GETs).
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminSpaController;

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
    fwrite(STDERR, "NO_STAFF_SESSION_USER\n");
    exit(2);
}

echo 'auth_user_id='.$user->id.' role='.$user->role.PHP_EOL;

// A) Direct controller after Auth::login (content + staff identity available)
Auth::login($user);
$direct = app(RpaAdminSpaController::class)();
$directBody = (string) $direct->getContent();
echo 'direct_controller_status='.$direct->getStatusCode().PHP_EOL;
echo 'direct_has_root='.(str_contains($directBody, 'id="root"') ? 'yes' : 'no').PHP_EOL;
echo 'direct_has_asset='.(str_contains($directBody, '/build-rpa-web/assets/index-OEOE0sHf.js') ? 'yes' : 'no').PHP_EOL;
echo 'direct_has_title='.(str_contains($directBody, 'RPA Social Automation') ? 'yes' : 'no').PHP_EOL;
echo 'direct_bytes='.strlen($directBody).PHP_EOL;

$disk = public_path(RpaAdminSpaController::INDEX_RELATIVE);
$diskHtml = is_file($disk) ? (string) file_get_contents($disk) : '';
echo 'direct_matches_disk='.($directBody === $diskHtml ? 'yes' : 'no').PHP_EOL;

// B) Full HTTP kernel with HTTPS + session user (Staff Session middleware path)
$paths = [
    '/social-automation/action-tags',
    '/social-automation/scripts',
    '/social-automation/accounts',
    '/social-automation/clients',
    '/social-automation/client-api-keys',
];

foreach ($paths as $path) {
    Auth::login($user);
    if (method_exists($user, 'getAuthPassword')) {
        session()->put('password_hash_web', $user->getAuthPassword());
    }

    $request = Request::create(
        'https://seo-ops.duckdns.org'.$path,
        'GET',
        [],
        [],
        [],
        [
            'HTTP_HOST' => 'seo-ops.duckdns.org',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REQUEST_SCHEME' => 'https',
        ]
    );
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(static fn () => Auth::user());

    $response = $kernel->handle($request);
    $body = (string) $response->getContent();
    echo sprintf(
        "KERNEL_SPA path=%s status=%d root=%s asset=%s title=%s bytes=%d loc=%s\n",
        $path,
        $response->getStatusCode(),
        (str_contains($body, 'id="root"') ? 'yes' : 'no'),
        (str_contains($body, '/build-rpa-web/assets/') ? 'yes' : 'no'),
        (str_contains($body, 'RPA Social Automation') ? 'yes' : 'no'),
        strlen($body),
        (string) $response->headers->get('Location')
    );
    $kernel->terminate($request, $response);
}

// C) Action Tags live GET summary
Auth::login($user);
$liveReq = Request::create(
    'https://seo-ops.duckdns.org/social-automation/action-tags',
    'GET',
    [],
    [],
    [],
    [
        'HTTP_HOST' => 'seo-ops.duckdns.org',
        'HTTPS' => 'on',
        'SERVER_PORT' => '443',
        'REQUEST_SCHEME' => 'https',
    ]
);
$liveReq->setLaravelSession(session()->driver());
$liveReq->setUserResolver(static fn () => Auth::user());
$liveRes = $kernel->handle($liveReq);
$liveBody = (string) $liveRes->getContent();
echo 'action_tags_live_status='.$liveRes->getStatusCode().PHP_EOL;
echo 'action_tags_matches_disk='.($liveBody === $diskHtml ? 'yes' : 'no').PHP_EOL;
echo 'action_tags_live_snip='.substr(preg_replace('/\s+/', ' ', $liveBody) ?? '', 0, 200).PHP_EOL;
$kernel->terminate($liveReq, $liveRes);

Auth::logout();
echo "CP9D_AUTH_VERIFY2_DONE\n";
