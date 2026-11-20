<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveSocketTicketJti extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_socket_ticket_jtis';

    protected $fillable = [
        'jti', 'installation_id', 'client_api_key_id', 'role',
        'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'client_api_key_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
