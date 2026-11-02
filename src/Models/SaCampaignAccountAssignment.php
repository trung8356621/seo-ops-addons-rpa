<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaCampaignAccountAssignment extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaign_account_assignments';

    protected $fillable = [
        'campaign_id', 'account_id', 'content_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'account_id' => 'integer',
            'content_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SaCampaign::class, 'campaign_id');
    }
}
