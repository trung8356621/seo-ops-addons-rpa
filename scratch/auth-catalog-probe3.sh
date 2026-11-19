#!/bin/bash
set -euo pipefail
cd /www/wwwroot/seo-ops-client
sudo -u www php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection("omi_social_automation");

$pkg = $db->table("sa_packages")->where("id", 3)->first();
echo "PKG3=" . json_encode(array(
  "id"=>(int)$pkg->id,
  "package_key"=>$pkg->package_key,
  "kind"=>$pkg->kind,
  "name"=>$pkg->name,
  "is_authentication"=>(bool)$pkg->is_authentication,
  "is_enabled"=>(bool)$pkg->is_enabled,
  "sync_source"=>$pkg->sync_source ?? null,
)) . PHP_EOL;

$rel = $db->table("sa_package_releases")->where("id", 3)->first();
$manifest = is_string($rel->manifest ?? null) ? json_decode($rel->manifest, true) : null;
$summary = null;
if (is_array($manifest)) {
  $summary = array(
    "action_tag_key" => $manifest["action_tag_key"] ?? null,
    "script_id" => $manifest["script_id"] ?? null,
    "variables_count" => isset($manifest["variables"]) && is_array($manifest["variables"]) ? count($manifest["variables"]) : null,
    "steps_count" => isset($manifest["steps"]) && is_array($manifest["steps"]) ? count($manifest["steps"]) : null,
    "assets_count" => isset($manifest["assets"]) && is_array($manifest["assets"]) ? count($manifest["assets"]) : null,
    "runtime_requirements_count" => isset($manifest["runtime_requirements"]) && is_array($manifest["runtime_requirements"]) ? count($manifest["runtime_requirements"]) : null,
    "keys" => array_keys($manifest),
    "empty_shell" => (
      (isset($manifest["variables"]) && is_array($manifest["variables"]) && count($manifest["variables"])===0)
      && (isset($manifest["steps"]) && is_array($manifest["steps"]) && count($manifest["steps"])===0)
      && (isset($manifest["assets"]) && is_array($manifest["assets"]) && count($manifest["assets"])===0)
    ),
  );
}
echo "REL3=" . json_encode(array(
  "id"=>(int)$rel->id,
  "package_id"=>(int)$rel->package_id,
  "version"=>$rel->version,
  "status"=>$rel->status,
  "published_at"=>$rel->published_at,
  "content_digest"=>$rel->content_digest,
  "manifest_summary"=>$summary,
)) . PHP_EOL;

// Also check package 5 (create post wp) and 4 for completeness
foreach (array(4,5) as $pid) {
  $p = $db->table("sa_packages")->where("id",$pid)->first();
  $latest = $db->table("sa_package_releases")->where("package_id",$pid)->orderByDesc("id")->first();
  $m = $latest && is_string($latest->manifest) ? json_decode($latest->manifest, true) : null;
  echo "PKG".$pid."_ATK=" . json_encode(array(
    "package_key"=>$p->package_key,
    "is_authentication"=>(bool)$p->is_authentication,
    "release_id"=>$latest ? (int)$latest->id : null,
    "action_tag_key"=> is_array($m) ? ($m["action_tag_key"] ?? null) : null,
    "steps_count"=> is_array($m) && isset($m["steps"]) && is_array($m["steps"]) ? count($m["steps"]) : null,
  )) . PHP_EOL;
}

$catalog = app(Omnichannel\Addons\SocialAutomation\Services\AuthScriptService::class)->listAuthenticationCatalog();
echo "CATALOG=" . json_encode($catalog) . PHP_EOL;
'
