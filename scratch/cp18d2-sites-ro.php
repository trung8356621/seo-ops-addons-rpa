<?php

declare(strict_types=1);

/**
 * READ-ONLY Site Registry inspection for CP18D.2.
 * Does not mutate any rows.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = 'omi_social_automation';
$rows = Illuminate\Support\Facades\DB::connection($conn)
    ->table('sa_sites')
    ->select(['id', 'key', 'name', 'base_url', 'base_url_mode', 'test_base_url'])
    ->orderBy('key')
    ->get();

echo "COUNT=".$rows->count().PHP_EOL;
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_SLASHES).PHP_EOL;
}

$m = $rows->first(static fn ($r) => strtolower((string) $r->key) === 'mayhopphat');
echo 'MAYHOPPHAT='.($m ? json_encode($m, JSON_UNESCAPED_SLASHES) : 'NOT_FOUND').PHP_EOL;

$cols = Illuminate\Support\Facades\Schema::connection($conn)->getColumnListing('sa_sites');
echo 'COLUMNS='.implode(',', $cols).PHP_EOL;
echo 'HAS_INSTALLATION_ID='.(in_array('installation_id', $cols, true) ? 'yes' : 'no').PHP_EOL;
