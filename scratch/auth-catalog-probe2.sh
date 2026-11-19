#!/bin/bash
set -euo pipefail
cd /www/wwwroot/seo-ops-client
sudo -u www php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection("omi_social_automation");

echo "PKG_LIKE=" . json_encode($db->table("sa_packages")->where("package_key","like","%mayhopphat%")->orWhere("package_key","like","%login%")->orderBy("id")->get(array("id","package_key","kind","is_authentication","is_enabled","name","created_at","updated_at"))) . PHP_EOL;

echo "PKG_RECENT=" . json_encode($db->table("sa_packages")->orderByDesc("id")->limit(15)->get(array("id","package_key","kind","is_authentication","is_enabled","name","updated_at"))) . PHP_EOL;

echo "REL_RECENT=" . json_encode($db->table("sa_package_releases")->orderByDesc("id")->limit(15)->get(array("id","package_id","version","status","published_at","created_at"))) . PHP_EOL;

$links = array();
if (\Illuminate\Support\Facades\Schema::connection("omi_social_automation")->hasTable("sa_action_tag_scripts")) {
  foreach ($db->table("sa_action_tag_scripts")->where("action_tag_key","mayhopphat.login")->orWhere("action_tag_id",6)->get() as $l) {
    $links[] = $l;
  }
}
echo "TAG_LINKS=" . json_encode($links) . PHP_EOL;

echo "REQ_ALL_STATUS=" . json_encode($db->table("sa_script_requests")->where("action_tag_key","mayhopphat.login")->get(array("id","request_id","status","script_id","script_version","updated_at","created_at"))) . PHP_EOL;

echo "PKG_COUNT=" . $db->table("sa_packages")->count() . PHP_EOL;
echo "REL_COUNT=" . $db->table("sa_package_releases")->count() . PHP_EOL;
echo "PUBLISHED_COUNT=" . $db->table("sa_package_releases")->where("status","published")->count() . PHP_EOL;
'
