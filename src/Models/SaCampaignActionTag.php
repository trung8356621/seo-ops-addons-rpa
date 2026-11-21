<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $plan_tag_uuid
 * @property int $campaign_id
 * @property int $action_tag_id
 * @property string $action_tag_key
 * @property int|null $site_id
 * @property string $site_key
 * @property string|null $cms_type
 * @property string|null $platform
 * @property int $package_id
 * @property string $script_id
 * @property string|null $script_name
 * @property int $release_id
 * @property string $script_version
 * @property string|null $script_content_digest
 * @property string|null $script_execution_digest
 * @property string $resolution_source
 * @property string|null $source_action_tag_key
 * @property array<string, mixed>|null $cms_scope
 * @property array<string, mixed>|null $manifest_snapshot
 * @property array<string, mixed>|null $shortcode_mapping
 * @property int $shortcode_mapping_version
 * @property bool $is_enabled
 * @property int $sort_order
 */
final class SaCampaignActionTag extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaign_action_tags';

    protected $fillable = [
        'plan_tag_uuid',
        'campaign_id',
        'action_tag_id',
        'action_tag_key',
        'site_id',
        'site_key',
        'cms_type',
        'platform',
        'package_id',
        'script_id',
        'script_name',
        'release_id',
        'script_version',
        'script_content_digest',
        'script_execution_digest',
        'resolution_source',
        'source_action_tag_key',
        'cms_scope',
        'manifest_snapshot',
        'shortcode_mapping',
        'shortcode_mapping_version',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'action_tag_id' => 'integer',
            'site_id' => 'integer',
            'package_id' => 'integer',
            'release_id' => 'integer',
            'cms_scope' => 'array',
            'manifest_snapshot' => 'array',
            'shortcode_mapping' => 'array',
            'shortcode_mapping_version' => 'integer',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<SaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SaCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<SaActionTag, $this> */
    public function actionTag(): BelongsTo
    {
        return $this->belongsTo(SaActionTag::class, 'action_tag_id');
    }

    /** @return BelongsTo<SaSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SaSite::class, 'site_id');
    }

    /** @return BelongsTo<SaPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(SaPackage::class, 'package_id');
    }

    /** @return BelongsTo<SaPackageRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(SaPackageRelease::class, 'release_id');
    }

    /** @return HasMany<SaCampaignContentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SaCampaignContentAssignment::class, 'campaign_action_tag_id');
    }
}
