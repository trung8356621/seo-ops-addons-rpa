<?php

declare(strict_types=1);

$cl = '/www/wwwroot/seo-ops-client';
require $cl.'/vendor/autoload.php';
$app = require $cl.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = DB::connection('omi_social_automation');

$site = $db->table('sa_sites')->where('id', 4)->first();
$path = $db->table('sa_paths')->where('id', 5)->first();
$actions = $db->table('sa_actions')->orderBy('id')->get(['id', 'key', 'name']);
$tags = $db->table('sa_action_tags')->where('site_id', 4)->where('path_id', 5)->get(['id', 'tag_key', 'action_key', 'name']);

echo 'SITE='.json_encode($site, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'PATH='.json_encode($path, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'ACTIONS='.json_encode($actions, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'TAGS_SITE4_PATH5='.json_encode($tags, JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'TAG_COUNT='.$tags->count().PHP_EOL;
