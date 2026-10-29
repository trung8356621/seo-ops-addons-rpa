<?php

declare(strict_types=1);

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$u = App\Models\User::query()->find(3);
echo 'id='.($u->id ?? 'none').PHP_EOL;
echo 'email='.($u->email ?? 'none').PHP_EOL;
echo 'name='.($u->name ?? '').PHP_EOL;
echo 'role='.($u->role ?? '').PHP_EOL;
