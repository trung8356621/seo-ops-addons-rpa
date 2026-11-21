<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $assignment_uuid
 * @property int $campaign_id
 * @property int $campaign_content_id
 * @property int $campaign_action_tag_id
 * @property int $package_id
 * @property int $release_id
 * @property string $script_id
 * @property string $script_version
 * @property string $source_format
 * @property array<string, mixed>|null $shortcode_mapping
 * @property int $mapping_version
 * @property array<string, mixed>|null $input
 * @property string $status
 */
final class SaCampaignContentAssignment extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_campaign_content_assignments';

    protected $fillable = [
        'assignment_uuid',
        'campaign_id',
        'campaign_content_id',
        'campaign_action_tag_id',
        'package_id',
        'release_id',
        'script_id',
        'script_version',
        'source_format',
        'shortcode_mapping',
        'mapping_version',
        'input',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'campaign_content_id' => 'integer',
            'campaign_action_tag_id' => 'integer',
            'package_id' => 'integer',
            'release_id' => 'integer',
            'shortcode_mapping' => 'array',
            'mapping_version' => 'integer',
            'input' => 'array',
        ];
    }

    /** @return BelongsTo<SaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SaCampaign::class, 'campaign_id');
    }

    /** @return BelongsTo<SaCampaignContent, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(SaCampaignContent::class, 'campaign_content_id');
    }

    /** @return BelongsTo<SaCampaignActionTag, $this> */
    public function campaignActionTag(): BelongsTo
    {
        return $this->belongsTo(SaCampaignActionTag::class, 'campaign_action_tag_id');
    }
}
