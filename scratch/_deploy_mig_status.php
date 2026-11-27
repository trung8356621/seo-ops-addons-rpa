<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (['mysql', 'omi_social_automation'] as $c) {
    try {
        $n = DB::connection($c)->table('migrations')->count();
        echo "CONN={$c} COUNT={$n}\n";
        $last = DB::connection($c)->table('migrations')->orderByDesc('id')->limit(8)->get(['migration', 'batch']);
        foreach ($last as $r) {
            echo '  '.$r->migration.' batch='.$r->batch.PHP_EOL;
        }
        $hit = DB::connection($c)->table('migrations')
            ->where('migration', 'like', '%issued_by%')
            ->orWhere('migration', 'like', '%client_api_keys%')
            ->get(['migration', 'batch']);
        echo 'HIT_'.strtoupper($c).'='.json_encode($hit).PHP_EOL;
    } catch (Throwable $e) {
        echo "CONN={$c} ERR=".$e->getMessage().PHP_EOL;
    }
}

$own = '/www/wwwroot/seo-ops-client/config/addon_migration_ownership.php';
echo 'OWNERSHIP_FILE='.(is_file($own) ? 'yes' : 'no').PHP_EOL;
if (is_file($own)) {
    $cfg = include $own;
    echo 'OWNERSHIP_KEYS='.implode(',', array_keys(is_array($cfg) ? $cfg : [])).PHP_EOL;
    if (isset($cfg['social-automation'])) {
        echo 'SA_OWNERSHIP='.json_encode($cfg['social-automation']).PHP_EOL;
    }
}
