<?php

declare(strict_types=1);

/**
 * Read-only probe: missing-build SPA response on VPS (no HTTP auth session needed).
 * Usage: sudo -u www php /tmp/cp9b1-missing-build-ro.php
 */

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = public_path('build-rpa-web/index.html');
echo 'index_path='.$path.PHP_EOL;
echo 'index_exists='.(is_file($path) ? 'yes' : 'no').PHP_EOL;

$r = Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminSpaController::responseForIndex($path);
$body = (string) $r->getContent();

echo 'status='.$r->getStatusCode().PHP_EOL;
echo 'ctype='.$r->headers->get('Content-Type').PHP_EOL;
echo 'cache='.$r->headers->get('Cache-Control').PHP_EOL;
echo 'body_snip='.substr($body, 0, 200).PHP_EOL;
echo 'has_build_path='.(str_contains($body, 'build-rpa-web') ? 'yes' : 'no').PHP_EOL;
echo 'has_exception='.(str_contains($body, 'Exception') ? 'yes' : 'no').PHP_EOL;
echo 'has_stack='.(str_contains(strtolower($body), 'stack') ? 'yes' : 'no').PHP_EOL;
echo 'has_unavailable='.(str_contains($body, 'temporarily unavailable') ? 'yes' : 'no').PHP_EOL;
echo 'MISSING_BUILD_PROBE_DONE'.PHP_EOL;
