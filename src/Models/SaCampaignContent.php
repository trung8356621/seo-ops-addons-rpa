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
 * @property int|null $article_id
 * @property string|null $article_uuid
 * @property string|null $external_ref
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property int|null $assigned_account_id
 * @property int|null $current_job_id
 * @property \Illuminate\Support\Carbon|null $snapshot_at
 * @property array<string, mixed>|null $snapshot_payload
 */
final class SaCampaignContent extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaign_contents';

    protected $fillable = [
        'content_uuid', 'campaign_id', 'article_id', 'article_uuid', 'external_ref', 'sort_order', 'status', 'input',
        'source_format', 'raw_source', 'mapping_version', 'snapshot_at', 'snapshot_payload',
        'assigned_account_id', 'current_job_id', 'last_error_code', 'last_error_message',
        'last_error_meta', 'admin_action_idempotency', 'admin_action_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'sort_order' => 'integer',
            'input' => 'array',
            'mapping_version' => 'integer',
            'snapshot_at' => 'datetime',
            'snapshot_payload' => 'array',
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

    /** @return BelongsTo<SaArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(SaArticle::class, 'article_id');
    }

    /** @return HasMany<SaJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(SaJob::class, 'campaign_content_id');
    }

    /** @return HasMany<SaCampaignContentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SaCampaignContentAssignment::class, 'campaign_content_id');
    }
}
