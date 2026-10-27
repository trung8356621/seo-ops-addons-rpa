<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Models;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;
final class SaAccountDeviceAffinity extends Model
{
    use UsesSocialAutomationConnection;
    protected $table = 'sa_account_device_affinities';
    protected $guarded = [];
    protected function casts(): array { return ['last_used_at'=>'datetime','affinity_until'=>'datetime']; }
}
