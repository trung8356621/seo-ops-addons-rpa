<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveRouteState extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_route_states';

    protected $fillable = [
        'installation_id', 'subject_type', 'subject_key',
        'route_generation', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'route_generation' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }
}
