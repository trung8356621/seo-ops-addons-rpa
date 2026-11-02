<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $campaign_uuid
 * @property string $installation_id
 * @property string $name
 * @property string $platform
 * @property string $execution_mode
 * @property string $run_mode
 * @property string $status
 * @property string $script_id
 * @property string $script_version
 * @property string|null $script_content_digest
 * @property int $resource_recheck_seconds
 * @property \Illuminate\Support\Carbon|null $next_recheck_at
 */
final class SaCampaign extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaigns';

    protected $fillable = [
        'campaign_uuid', 'installation_id', 'name', 'platform', 'execution_mode', 'run_mode', 'status',
        'script_id', 'script_version', 'script_content_digest', 'resource_recheck_seconds',
        'next_recheck_at', 'started_at', 'paused_at', 'cancelled_at', 'completed_at',
        'created_by_user_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'resource_recheck_seconds' => 'integer',
            'created_by_user_id' => 'integer',
            'meta' => 'array',
            'next_recheck_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
}
