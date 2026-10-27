<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;
final class SaClientNotification extends Model
{
    use UsesSocialAutomationConnection;
    protected $table = 'sa_client_notifications';
    protected $guarded = [];
    protected function casts(): array { return ['delivered_at'=>'datetime','acked_at'=>'datetime','created_by_user_id'=>'integer']; }

    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(SaBrowserSession::class, 'browser_session_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaAccount::class, 'account_id');
    }
}
