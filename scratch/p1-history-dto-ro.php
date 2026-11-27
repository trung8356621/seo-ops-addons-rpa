<?php

declare(strict_types=1);

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Services\CampaignContentHistoryService;

$camp = SaCampaign::query()->where('campaign_uuid', 'd70d1999-5c71-46f8-a3a4-b0f04014aa46')->firstOrFail();
$contents = SaCampaignContent::query()->where('campaign_id', $camp->id)->where('id', 122)->get();
$grouped = (new CampaignContentHistoryService)->attemptsByContentId($camp, $contents);
echo json_encode([
    'mutated' => false,
    'content_id' => 122,
    'attempt_rows' => $grouped[122] ?? [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
