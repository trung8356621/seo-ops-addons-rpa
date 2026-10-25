<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Site registry entity representing a destination platform (e.g. Facebook, X, Reddit).
 */
class SaSite extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_sites';

    protected $guarded = [];

    /**
     * @return HasMany<SaPath>
     */
    public function paths(): HasMany
    {
        return $this->hasMany(SaPath::class, 'site_id');
    }

    /**
     * @return HasMany<SaActionTag>
     */
    public function actionTags(): HasMany
    {
        return $this->hasMany(SaActionTag::class, 'site_id');
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        // Ensure scheme
        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        return rtrim($trimmed, '/');
    }
}
