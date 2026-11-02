<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $content_uuid
 * @property int $campaign_id
 * @property string|null $external_ref
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property int|null $assigned_account_id
 * @property int|null $current_job_id
 */
final class SaCampaignContent extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaign_contents';

    protected $fillable = [
        'content_uuid', 'campaign_id', 'external_ref', 'sort_order', 'status', 'input',
        'assigned_account_id', 'current_job_id', 'last_error_code', 'last_error_message',
        'last_error_meta', 'admin_action_idempotency', 'admin_action_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'input' => 'array',
            'last_error_meta' => 'array',
            'assigned_account_id' => 'integer',
            'current_job_id' => 'integer',
            'admin_action_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SaCampaign::class, 'campaign_id');
    }

    /** @return HasMany<SaJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(SaJob::class, 'campaign_content_id');
    }
}
