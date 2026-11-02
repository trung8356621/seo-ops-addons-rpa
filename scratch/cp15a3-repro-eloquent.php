<?php

declare(strict_types=1);

require 'D:/work/omnichannel-client/vendor/autoload.php';

use Omnichannel\Addons\SocialAutomation\Models\SaAgent;

$a = new SaAgent;
try {
    $a->display_name = 'x';
    echo "display_name OK value=".$a->display_name.PHP_EOL;
} catch (Throwable $e) {
    echo 'display_name FAIL: '.$e->getMessage().PHP_EOL;
}

try {
    $a->client_api_key_id = 42;
    echo 'client_api_key_id OK'.PHP_EOL;
} catch (Throwable $e) {
    echo 'client_api_key_id FAIL: '.$e->getMessage().PHP_EOL;
}

try {
    $a->last_heartbeat_at = new DateTimeImmutable('2026-10-29 11:59:30');
    echo 'heartbeat assign OK'.PHP_EOL;
    echo 'heartbeat read='.get_debug_type($a->last_heartbeat_at).PHP_EOL;
} catch (Throwable $e) {
    echo 'heartbeat FAIL: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
}

try {
    $a->setRawAttributes([
        'display_name' => 'y',
        'client_api_key_id' => 3,
        'last_heartbeat_at' => new DateTimeImmutable('2026-10-29 11:59:40'),
    ], true);
    echo 'setRawAttributes OK'.PHP_EOL;
    $v = $a->getAttributes()['last_heartbeat_at'] ?? null;
    echo 'raw type='.get_debug_type($v).PHP_EOL;
    echo 'attr read type='.get_debug_type($a->last_heartbeat_at).PHP_EOL;
} catch (Throwable $e) {
    echo 'setRaw/read FAIL: '.$e->getMessage().PHP_EOL;
}

use Omnichannel\Addons\SocialAutomation\Models\SaBrowserSession;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Registry\SessionSyncService;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;

$s = new SaBrowserSession;
try {
    $s->capabilities = ['name' => 'A', 'status' => 'running'];
    echo 'caps assign OK'.PHP_EOL;
} catch (Throwable $e) {
    echo 'caps assign FAIL: '.$e->getMessage().PHP_EOL;
}

$s->setRawAttributes([
    'session_uuid' => 'brw-111',
    'account_id' => 7,
    'platform' => 'facebook',
    'ready_status' => 'ready',
    'session_state' => 'verified',
    'capabilities' => json_encode([
        'name' => 'Chrome Profile A',
        'status' => 'running',
        'last_reported_at' => '2026-10-29T11:59:40+00:00',
    ], JSON_THROW_ON_ERROR),
], true);
$a->setRawAttributes([
    'display_name' => 'Office-PC-01',
    'device_id' => 'dev-abc',
    'client_api_key_id' => 42,
    'last_heartbeat_at' => new DateTimeImmutable('2026-10-29 11:59:30'),
], true);
$acc = new SaAccount;
$acc->setRawAttributes([
    'id' => 7,
    'account_uuid' => 'acc-uuid-7',
    'name' => 'Brand Page',
    'username' => 'brand.user',
    'platform' => 'facebook',
    'site_url' => 'https://facebook.com',
], true);
$now = new DateTimeImmutable('2026-10-29 12:00:00');
$row = (new SessionSyncService(new OnlineStateCalculator(90)))->mapStaffBrowserRow($s, $a, $acc, $now);
echo 'map OK browser_id='.$row['browser_id'].' is_online='.json_encode($row['is_online']).PHP_EOL;
