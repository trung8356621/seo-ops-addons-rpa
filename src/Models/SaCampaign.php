<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * @property int $id
 * @property string $campaign_uuid
 * @property string $installation_id
 * @property string $name
 * @property string $platform
 * @property string $execution_mode
 * @property string $run_mode
 * @property string $status
 * @property int $plan_version
 * @property string $script_id
 * @property string $script_version
 * @property string|null $script_content_digest
 * @property int $resource_recheck_seconds
 * @property \Illuminate\Support\Carbon|null $next_recheck_at
 * @property int|null $deleted_by_user_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class SaCampaign extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaigns';

    protected $fillable = [
        'campaign_uuid', 'installation_id', 'name', 'platform', 'execution_mode', 'run_mode', 'status', 'plan_version',
        'script_id', 'script_version', 'script_content_digest', 'resource_recheck_seconds',
        'next_recheck_at', 'started_at', 'paused_at', 'cancelled_at', 'completed_at',
        'deleted_at', 'deleted_by_user_id',
        'created_by_user_id', 'meta', 'shortcode_mapping', 'shortcode_mapping_version',
    ];

    protected function casts(): array
    {
        return [
            'plan_version' => 'integer',
            'resource_recheck_seconds' => 'integer',
            'created_by_user_id' => 'integer',
            'deleted_by_user_id' => 'integer',
            'meta' => 'array',
            'shortcode_mapping' => 'array',
            'shortcode_mapping_version' => 'integer',
            'next_recheck_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Exclude soft-deleted campaigns when the additive deleted_at column is present.
     *
     * @param  Builder<SaCampaign>  $query
     * @return Builder<SaCampaign>
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        if (SocialAutomationSchema::campaignSoftDeleteReady()) {
            return $query->whereNull($this->getTable().'.deleted_at');
        }

        return $query;
    }

    /** @return HasMany<SaCampaignContent, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(SaCampaignContent::class, 'campaign_id');
    }

    /** @return HasMany<SaCampaignAccountAssignment, $this> */
    public function accountAssignments(): HasMany
    {
        return $this->hasMany(SaCampaignAccountAssignment::class, 'campaign_id');
    }

    /** @return HasMany<SaCampaignActionTag, $this> */
    public function actionTags(): HasMany
    {
        return $this->hasMany(SaCampaignActionTag::class, 'campaign_id');
    }

    /** @return HasMany<SaCampaignContentAssignment, $this> */
    public function contentAssignments(): HasMany
    {
        return $this->hasMany(SaCampaignContentAssignment::class, 'campaign_id');
    }
}
