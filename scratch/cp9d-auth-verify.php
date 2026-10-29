<?php

declare(strict_types=1);

/**
 * CP9D read-only authenticated Staff Session SPA probe via HTTP kernel.
 * No DB writes beyond session (Auth::login). No production CRUD.
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
    ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_STAFF])
    ->where('status', '!=', User::STATUS_BLOCK)
    ->orderByRaw("FIELD(role, '".User::ROLE_STAFF."', '".User::ROLE_ADMIN."', '".User::ROLE_OWNER."')")
    ->orderBy('id')
    ->first();

if (! $user) {
    fwrite(STDERR, "NO_STAFF_SESSION_USER\n");
    exit(2);
}

echo 'auth_user_id='.$user->id.' role='.$user->role.PHP_EOL;

Auth::login($user);

$paths = [
    '/social-automation/action-tags',
    '/social-automation/scripts',
    '/social-automation/accounts',
    '/social-automation/clients',
    '/social-automation/client-api-keys',
];

foreach ($paths as $path) {
    $request = Request::create($path, 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    $ctype = (string) $response->headers->get('Content-Type');
    $hasRoot = str_contains($body, 'id="root"') || str_contains($body, "id='root'");
    $hasAsset = str_contains($body, '/build-rpa-web/assets/');
    $hasTitle = str_contains($body, 'RPA Social Automation');
    echo sprintf(
        "AUTH_SPA path=%s status=%d ctype=%s root=%s asset=%s title=%s bytes=%d\n",
        $path,
        $status,
        $ctype,
        $hasRoot ? 'yes' : 'no',
        $hasAsset ? 'yes' : 'no',
        $hasTitle ? 'yes' : 'no',
        strlen($body)
    );
    $kernel->terminate($request, $response);
}

// Action Tags live GET — confirm shell matches on-disk index.html
$disk = public_path(RpaAdminSpaController::INDEX_RELATIVE);
$diskHtml = is_file($disk) ? (string) file_get_contents($disk) : '';
$req = Request::create('/social-automation/action-tags', 'GET');
$res = $kernel->handle($req);
$live = (string) $res->getContent();
echo 'action_tags_live_status='.$res->getStatusCode().PHP_EOL;
echo 'action_tags_matches_disk='.($live === $diskHtml ? 'yes' : 'no').PHP_EOL;
echo 'action_tags_disk_bytes='.strlen($diskHtml).PHP_EOL;
echo 'action_tags_live_snip='.substr(preg_replace('/\s+/', ' ', $live) ?? '', 0, 180).PHP_EOL;
$kernel->terminate($req, $res);

Auth::logout();
echo "CP9D_AUTH_VERIFY_DONE\n";
