<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Registered CMS Type (e.g. wordpress, ghost, shopify).
 */
class SaCmsType extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_cms_types';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /**
     * @return HasMany<SaCmsPathTemplate>
     */
    public function pathTemplates(): HasMany
    {
        return $this->hasMany(SaCmsPathTemplate::class, 'cms_type_id');
    }

    /**
     * @return HasMany<SaSite>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(SaSite::class, 'cms_type', 'key');
    }

    public static function normalizeKey(string $raw): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($raw))) ?? '';
    }
}
