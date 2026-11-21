<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Shared Path Template belonging to a registered CMS Type.
 */
class SaCmsPathTemplate extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_cms_path_templates';

    protected $guarded = [];

    protected $casts = [
        'cms_type_id' => 'integer',
        'path_inputs' => 'array',
        'is_authentication' => 'boolean',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (SaCmsPathTemplate $tpl): void {
            if (! empty($tpl->url_pattern) && ($tpl->isDirty('url_pattern') || empty($tpl->path_inputs))) {
                $tpl->path_inputs = PathPatternResolver::parseInputs((string) $tpl->url_pattern);
            }
        });
    }

    /**
     * @return BelongsTo<SaCmsType, SaCmsPathTemplate>
     */
    public function cmsType(): BelongsTo
    {
        return $this->belongsTo(SaCmsType::class, 'cms_type_id');
    }
}
