<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveCampaignEvent extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_campaign_events';

    protected $fillable = [
        'event_id', 'installation_id', 'campaign_id', 'campaign_uuid',
        'job_id', 'attempt', 'fencing_generation', 'event_sequence',
        'occurred_at', 'payload_version', 'event_type',
        'source_kind', 'source_id', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'attempt' => 'integer',
            'fencing_generation' => 'integer',
            'event_sequence' => 'integer',
            'payload_version' => 'integer',
            'source_id' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
