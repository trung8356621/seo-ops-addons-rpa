<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Models;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;
final class SaAccountExecutionLease extends Model
{
    use UsesSocialAutomationConnection;
    protected $table = 'sa_account_execution_leases';
    protected $guarded = [];
    protected function casts(): array { return ['acquired_at'=>'datetime','heartbeat_at'=>'datetime','expires_at'=>'datetime','released_at'=>'datetime']; }
}
