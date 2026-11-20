<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveNotifyOutbox extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_notify_outbox';

    protected $fillable = [
        'installation_id', 'campaign_uuid', 'event_type', 'coalesce_key',
        'seq_hint', 'route_generation', 'assignment_generation',
        'rooms', 'payload', 'status', 'attempts', 'available_at',
        'published_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'seq_hint' => 'integer',
            'route_generation' => 'integer',
            'assignment_generation' => 'integer',
            'rooms' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
