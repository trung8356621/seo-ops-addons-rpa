#!/bin/bash
set -euo pipefail
cd /www/wwwroot/seo-ops-client
sudo -u www php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection("omi_social_automation");

$pkg = $db->table("sa_packages")->where("package_key", "mayhopphat.login")->first();
$out = array(
  "id" => $pkg ? (int)$pkg->id : null,
  "package_key" => $pkg->package_key ?? null,
  "kind" => $pkg->kind ?? null,
  "is_authentication" => $pkg ? (bool)$pkg->is_authentication : null,
  "is_enabled" => $pkg ? (bool)$pkg->is_enabled : null,
  "name" => $pkg->name ?? null,
);
echo "PACKAGE=" . json_encode($out) . PHP_EOL;

$releases = array();
if ($pkg) {
  foreach ($db->table("sa_package_releases")->where("package_id", $pkg->id)->orderByDesc("id")->get() as $r) {
    $raw = $db->table("sa_package_releases")->where("id", $r->id)->value("manifest");
    $summary = null;
    if (is_string($raw) && $raw !== "") {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $summary = array(
          "variables_count" => isset($decoded["variables"]) && is_array($decoded["variables"]) ? count($decoded["variables"]) : null,
          "steps_count" => isset($decoded["steps"]) && is_array($decoded["steps"]) ? count($decoded["steps"]) : null,
          "assets_count" => isset($decoded["assets"]) && is_array($decoded["assets"]) ? count($decoded["assets"]) : null,
          "keys" => array_keys($decoded),
        );
      }
    }
    $releases[] = array(
      "id" => (int)$r->id,
      "version" => $r->version,
      "status" => $r->status,
      "published_at" => $r->published_at,
      "content_digest" => $r->content_digest,
      "manifest_summary" => $summary,
    );
  }
}
echo "RELEASES=" . json_encode($releases) . PHP_EOL;

$path = $db->table("sa_paths")->where("site_key", "mayhopphat")->where("key", "login")->first();
$pathOut = $path ? array(
  "id" => (int)$path->id,
  "site_key" => $path->site_key,
  "key" => $path->key,
  "is_authentication" => (bool)$path->is_authentication,
) : null;
echo "PATH=" . json_encode($pathOut) . PHP_EOL;

$tags = array();
foreach ($db->table("sa_action_tags")->where("tag_key", "mayhopphat.login")->get() as $t) {
  $tags[] = array(
    "id" => (int)$t->id,
    "tag_key" => $t->tag_key,
    "site_id" => $t->site_id !== null ? (int)$t->site_id : null,
    "path_id" => $t->path_id !== null ? (int)$t->path_id : null,
    "path_key" => $t->path_key,
    "action_key" => $t->action_key,
    "auth_script_id" => $t->auth_script_id !== null ? (int)$t->auth_script_id : null,
    "auth_release_id" => $t->auth_release_id !== null ? (int)$t->auth_release_id : null,
  );
}
echo "TAGS=" . json_encode($tags) . PHP_EOL;

$reqs = array();
foreach ($db->table("sa_script_requests")->where("action_tag_key", "mayhopphat.login")->orderByDesc("id")->limit(5)->get() as $r) {
  $reqs[] = array(
    "id" => (int)$r->id,
    "request_id" => $r->request_id,
    "action_tag_id" => $r->action_tag_id !== null ? (int)$r->action_tag_id : null,
    "action_tag_key" => $r->action_tag_key,
    "path_key" => $r->path_key,
    "status" => $r->status,
    "script_id" => $r->script_id,
    "script_version" => $r->script_version,
    "request_type" => $r->request_type,
  );
}
echo "REQUESTS=" . json_encode($reqs) . PHP_EOL;

$svc = app(Omnichannel\Addons\SocialAutomation\Services\AuthScriptService::class);
$catalog = $svc->listAuthenticationCatalog();
$keys = array();
$hit = null;
foreach ($catalog as $row) {
  $keys[] = $row["package_key"] ?? null;
  if (($row["package_key"] ?? "") === "mayhopphat.login") {
    $hit = array(
      "package_id" => $row["package_id"] ?? null,
      "releases_count" => isset($row["releases"]) && is_array($row["releases"]) ? count($row["releases"]) : 0,
      "is_authentication" => $row["is_authentication"] ?? null,
    );
  }
}
echo "SCHEMA_READY=" . ($svc->schemaReady() ? "yes" : "no") . PHP_EOL;
echo "CATALOG_COUNT=" . count($catalog) . PHP_EOL;
echo "CATALOG_KEYS=" . json_encode($keys) . PHP_EOL;
echo "CATALOG_HIT=" . json_encode($hit) . PHP_EOL;

$authPkgs = array();
foreach ($db->table("sa_packages")->where("is_authentication", 1)->orderBy("package_key")->get(array("id","package_key","kind","is_enabled")) as $p) {
  $authPkgs[] = array("id"=>(int)$p->id,"package_key"=>$p->package_key,"kind"=>$p->kind,"is_enabled"=>(bool)$p->is_enabled);
}
echo "ALL_AUTH_PACKAGES=" . json_encode($authPkgs) . PHP_EOL;
'
