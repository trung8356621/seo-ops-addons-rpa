<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$digest = '5e0f3aafc2e1687359f4c9e64835160493d980122bfc185207671d18e53b8c9d';
$paths = [
    '18690ccb-86bf-4f5d-898a-565d1c23de3b.png',
    'ceb8b195-14b0-4383-a62f-c84aa433a3e6.png',
];

$candidates = [
    '/www/wwwroot/seo-ops-client/storage/app/social-automation',
    '/www/wwwroot/seo-ops-client/storage/app/omi_social_automation',
    '/www/wwwroot/seo-ops-client/storage/app/packages',
    '/www/wwwroot/seo-ops-addons/social-automation/storage',
];

$out = ['digest' => $digest, 'found' => []];
foreach ($candidates as $base) {
    $out['bases'][$base] = is_dir($base);
    if (! is_dir($base)) {
        continue;
    }
    foreach ([$digest, substr($digest, 0, 2).'/'.substr($digest, 2)] as $rel) {
        $dir = rtrim($base, '/').'/'.$rel;
        if (is_dir($dir)) {
            $out['found'][] = ['dir' => $dir, 'files' => array_values(array_diff(scandir($dir) ?: [], ['.', '..']))];
        }
    }
    foreach ($paths as $p) {
        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($it as $f) {
            if (++$n > 50000) {
                break;
            }
            if ($f->isFile() && $f->getFilename() === $p) {
                $hits[] = $f->getPathname();
                if (count($hits) >= 3) {
                    break 2;
                }
            }
        }
        $out['path_hits'][$p][$base] = $hits;
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
