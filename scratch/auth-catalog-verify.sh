#!/bin/bash
set -euo pipefail
cd /www/wwwroot/seo-ops-client
sudo -u www php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection("omi_social_automation");
$pkg = $db->table("sa_packages")->where("id", 3)->first();
echo "PKG3_FLAG=" . json_encode(array(
  "package_key"=>$pkg->package_key,
  "is_authentication"=>(bool)$pkg->is_authentication,
)) . PHP_EOL;
$svc = app(Omnichannel\Addons\SocialAutomation\Services\AuthScriptService::class);
$catalog = $svc->listAuthenticationCatalog();
$hit = null;
foreach ($catalog as $row) {
  if (($row["package_key"] ?? "") === "690493bd-a830-4648-83b3-86da1533c77a") {
    $hit = array(
      "package_id"=>$row["package_id"],
      "package_key"=>$row["package_key"],
      "name"=>$row["name"],
      "releases_count"=>isset($row["releases"]) ? count($row["releases"]) : 0,
      "release_versions"=>array_map(function($r){return $r["version"]??null;}, $row["releases"]??array()),
    );
  }
}
echo "SCHEMA_READY=" . ($svc->schemaReady() ? "yes" : "no") . PHP_EOL;
echo "CATALOG_COUNT=" . count($catalog) . PHP_EOL;
echo "CATALOG_HIT=" . json_encode($hit) . PHP_EOL;
echo "AUTH_IDS=" . json_encode($svc->authenticationPackageIds()) . PHP_EOL;
'
