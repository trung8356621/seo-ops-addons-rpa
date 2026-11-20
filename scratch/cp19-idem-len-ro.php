<?php
require "/www/wwwroot/seo-ops-client/vendor/autoload.php";
$app=require "/www/wwwroot/seo-ops-client/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cols=Illuminate\Support\Facades\DB::connection("omi_social_automation")->select("SHOW COLUMNS FROM sa_jobs WHERE Field = ?", ["idempotency_key"]);
$key="campaign:cf0d8be1-5475-4c84-a788-600a6a8ae17f:content:7ee8aabb-bf02-46ca-a867-14a839fc0f0e:attempt:1";
echo json_encode(["column"=>$cols,"legacy_len"=>strlen($key)], JSON_PRETTY_PRINT), "\n";
