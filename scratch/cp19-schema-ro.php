<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = Illuminate\Support\Facades\Schema::connection('omi_social_automation');
echo json_encode([
    'app_env' => (string) config('app.env'),
    'sa_campaigns' => $s->hasTable('sa_campaigns'),
    'sa_campaign_contents' => $s->hasTable('sa_campaign_contents'),
    'sa_campaign_account_assignments' => $s->hasTable('sa_campaign_account_assignments'),
    'orchestrator_has_hard_pin' => str_contains(
        (string) @file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/src/Orchestration/CampaignOrchestrator.php'),
        'resolveRequiredAccountId'
    ),
    'openapi_has_hard_pin' => str_contains(
        (string) @file_get_contents('/www/wwwroot/seo-ops-addons/social-automation/openapi/openapi.yaml'),
        'Hard Account UUID pin'
    ),
], JSON_PRETTY_PRINT).PHP_EOL;
