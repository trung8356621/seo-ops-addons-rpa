<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Path entity belonging to a Site. Represents a short name for a URL pattern.
 */
class SaPath extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_paths';

    protected $guarded = [];

    protected $casts = [
        'path_inputs' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (SaPath $path): void {
            if (empty($path->path_inputs) && ! empty($path->url_pattern)) {
                $path->path_inputs = PathPatternResolver::parseInputs($path->url_pattern);
            }
        });
    }

    /**
     * @return BelongsTo<SaSite, SaPath>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SaSite::class, 'site_id');
    }

    /**
     * @return HasMany<SaActionTag>
     */
    public function actionTags(): HasMany
    {
        return $this->hasMany(SaActionTag::class, 'path_id');
    }
}
