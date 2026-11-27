<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaRecoveryHandlerRequest extends Model
{
    use UsesSocialAutomationConnection;
    protected $table = 'sa_recovery_handler_requests';
    protected $guarded = [];
    protected function casts(): array { return ['owner_user_id'=>'integer','claimed_by_user_id'=>'integer','claimed_at'=>'datetime']; }
}
