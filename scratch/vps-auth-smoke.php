<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Models\SaRpaAdminSession;

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::query()
    ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
    ->where('status', '!=', User::STATUS_BLOCK)
    ->orderBy('id')
    ->first();

if (! $user) {
    fwrite(STDERR, "NO_ADMIN_USER\n");
    exit(2);
}

$plain = Str::random(48);
SaRpaAdminSession::query()->create([
    'user_id' => (int) $user->id,
    'token_hash' => hash('sha256', $plain),
    'token_hint' => substr($plain, -6),
    'expires_at' => now()->addMinutes(15),
    'last_seen_at' => now(),
    'client_label' => 'vps-auth-smoke',
]);

file_put_contents('/tmp/sa-smoke-token.txt', $plain);
echo 'SMOKE_TOKEN_ISSUED user_id='.$user->id.' role='.$user->role.PHP_EOL;
