<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * Explicit Account ↔ Action Tag authorization (SB1=A). Empty = fail closed.
 *
 * @property int $id
 * @property int $account_id
 * @property int $action_tag_id
 * @property int|null $granted_by_user_id
 * @property \Illuminate\Support\Carbon|null $granted_at
 */
final class SaAccountActionTag extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_account_action_tags';

    protected $fillable = [
        'account_id',
        'action_tag_id',
        'granted_by_user_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'action_tag_id' => 'integer',
            'granted_by_user_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SaAccount::class, 'account_id');
    }

    /** @return BelongsTo<SaActionTag, $this> */
    public function actionTag(): BelongsTo
    {
        return $this->belongsTo(SaActionTag::class, 'action_tag_id');
    }
}
