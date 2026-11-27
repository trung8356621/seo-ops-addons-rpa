<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaRecoveryResult extends Model
{
    use UsesSocialAutomationConnection;
    protected $table = 'sa_recovery_results';
    protected $guarded = [];
    protected function casts(): array { return ['runtime_input'=>'array','evidence'=>'array','match_confidence'=>'float','recovery_attempt_no'=>'integer','handler_started_at'=>'datetime','handler_finished_at'=>'datetime']; }
}
