<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveEventAckCursor extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_event_ack_cursors';

    protected $fillable = [
        'installation_id', 'node_id', 'campaign_id', 'campaign_uuid',
        'acked_through_sequence', 'acked_event_count', 'last_acked_at',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'acked_through_sequence' => 'integer',
            'acked_event_count' => 'integer',
            'last_acked_at' => 'datetime',
        ];
    }
}
