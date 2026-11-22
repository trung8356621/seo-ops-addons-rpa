<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * Independent Article Library entity.
 * Belongs to the Article Library (no campaign_id) and binds to exactly one Action Tag.
 *
 * @property int $id
 * @property string $article_uuid
 * @property string $installation_id
 * @property string $title
 * @property string|null $external_ref
 * @property int $action_tag_id
 * @property string $action_tag_key
 * @property int|null $site_id
 * @property string|null $site_key
 * @property string $status
 * @property string $source_format
 * @property string|null $raw_source
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $shortcode_mapping
 * @property int $mapping_version
 * @property int|null $created_by_user_id
 * @property array<string, mixed>|null $meta
 */
final class SaArticle extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_articles';

    protected $fillable = [
        'article_uuid',
        'installation_id',
        'title',
        'external_ref',
        'action_tag_id',
        'action_tag_key',
        'site_id',
        'site_key',
        'status',
        'source_format',
        'raw_source',
        'input',
        'shortcode_mapping',
        'mapping_version',
        'created_by_user_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'action_tag_id' => 'integer',
            'site_id' => 'integer',
            'input' => 'array',
            'shortcode_mapping' => 'array',
            'mapping_version' => 'integer',
            'created_by_user_id' => 'integer',
            'meta' => 'array',
        ];
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

    /** @return HasMany<SaCampaignContent, $this> */
    public function campaignContents(): HasMany
    {
        return $this->hasMany(SaCampaignContent::class, 'article_id');
    }
}
