#!/bin/bash
set -euo pipefail
PHP=/www/server/php/83/bin/php
CD=/www/wwwroot/seo-ops-client
MIG=2026_11_25_130000_add_issued_by_to_sa_client_api_keys_table.php

echo "=== resolve paths ==="
ls -la /www/wwwroot/seo-ops-addons/social-automation/database/migrations/$MIG
if [ -e /www/wwwroot/seo-ops-client/addons/social-automation ]; then
  ls -la /www/wwwroot/seo-ops-client/addons/social-automation | head -3
  if [ -f /www/wwwroot/seo-ops-client/addons/social-automation/database/migrations/$MIG ]; then
    PATH_REL="addons/social-automation/database/migrations/$MIG"
  else
    PATH_REL=""
  fi
else
  PATH_REL=""
fi
PATH_ABS="/www/wwwroot/seo-ops-addons/social-automation/database/migrations/$MIG"
echo "PATH_REL=${PATH_REL:-none}"
echo "PATH_ABS=$PATH_ABS"

echo "=== local sha ==="
sha256sum "$PATH_ABS"

echo "=== pre column ==="
$PHP -r '
require "/www/wwwroot/seo-ops-client/vendor/autoload.php";
$app=require "/www/wwwroot/seo-ops-client/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "HAS_ISSUED_BY_PRE=".(Illuminate\Support\Facades\Schema::connection("omi_social_automation")->hasColumn("sa_client_api_keys","issued_by")?"yes":"no").PHP_EOL;
$c=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->count();
echo "KEY_COUNT_PRE=$c".PHP_EOL;
$qa=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->where("id",10)->first(["id","name","created_by","revoked_at"]);
echo "QA10_PRE=".json_encode($qa).PHP_EOL;
'

echo "=== migrate status (path) ==="
cd "$CD"
if [ -n "${PATH_REL}" ]; then
  $PHP artisan migrate:status --database=omi_social_automation --path="$PATH_REL" 2>&1 | tail -20
else
  $PHP artisan migrate:status --database=omi_social_automation --path="$PATH_ABS" --realpath 2>&1 | tail -20
fi

echo "=== migrate once ==="
if [ -n "${PATH_REL}" ]; then
  $PHP artisan migrate --database=omi_social_automation --path="$PATH_REL" --force 2>&1
else
  $PHP artisan migrate --database=omi_social_automation --path="$PATH_ABS" --realpath --force 2>&1
fi

echo "=== post checks ==="
$PHP -r '
require "/www/wwwroot/seo-ops-client/vendor/autoload.php";
$app=require "/www/wwwroot/seo-ops-client/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schema=Illuminate\Support\Facades\Schema::connection("omi_social_automation");
echo "HAS_ISSUED_BY_POST=".($schema->hasColumn("sa_client_api_keys","issued_by")?"yes":"no").PHP_EOL;
$cols=Illuminate\Support\Facades\DB::connection("omi_social_automation")->select("SHOW COLUMNS FROM sa_client_api_keys LIKE \"issued_by\"");
echo "COL_DEF=".json_encode($cols).PHP_EOL;
$c=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->count();
echo "KEY_COUNT_POST=$c".PHP_EOL;
$qa=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->where("id",10)->first(["id","name","created_by","issued_by","revoked_at"]);
echo "QA10_POST=".json_encode($qa).PHP_EOL;
$created=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->orderBy("id")->get(["id","created_by"]);
echo "CREATED_BY_MAP=".json_encode($created).PHP_EOL;
$mig=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("migrations")->where("migration","like","%issued_by_to_sa_client_api_keys%")->get(["migration","batch"]);
echo "MIG_ROWS=".json_encode($mig).PHP_EOL;
$nullIssued=Illuminate\Support\Facades\DB::connection("omi_social_automation")->table("sa_client_api_keys")->whereNull("issued_by")->count();
echo "ISSUED_BY_NULL_COUNT=$nullIssued".PHP_EOL;
'

echo "=== idempotent second migrate (should no-op) ==="
if [ -n "${PATH_REL}" ]; then
  $PHP artisan migrate --database=omi_social_automation --path="$PATH_REL" --force 2>&1
else
  $PHP artisan migrate --database=omi_social_automation --path="$PATH_ABS" --realpath --force 2>&1
fi
