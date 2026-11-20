<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveCampaignSequence extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_campaign_sequences';

    protected $fillable = [
        'installation_id', 'campaign_id', 'campaign_uuid',
        'last_sequence', 'snapshot_version',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'last_sequence' => 'integer',
            'snapshot_version' => 'integer',
        ];
    }
}
