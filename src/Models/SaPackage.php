<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * @property int $id
 * @property string $package_key
 * @property string $kind
 * @property string $name
 * @property string|null $description
 */
final class SaPackage extends Model
{
    public const SHARING_SITE_SPECIFIC = 'site_specific';

    public const SHARING_CMS_GLOBAL = 'cms_global';

    /** @var list<string> */
    public const SHARING_MODES = [
        self::SHARING_SITE_SPECIFIC,
        self::SHARING_CMS_GLOBAL,
    ];

    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_packages';

    protected $fillable = [
        'package_key',
        'kind',
        'name',
        'language_label',
        'description',
        'is_enabled',
        'is_primary',
        'is_authentication',
        'sharing_mode',
        'global_cms_type',
        'global_cms_template_id',
        'global_cms_path_key',
        'global_scope_key',
        'disabled_reason',
        'disabled_meta',
        'created_by_user_id',
        'updated_by_user_id',
        'sync_source',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_primary' => 'boolean',
            'is_authentication' => 'boolean',
            'global_cms_template_id' => 'integer',
            'disabled_meta' => 'array',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    public function sharingMode(): string
    {
        $mode = (string) ($this->sharing_mode ?? self::SHARING_SITE_SPECIFIC);

        return in_array($mode, self::SHARING_MODES, true) ? $mode : self::SHARING_SITE_SPECIFIC;
    }

    public function isCmsGlobal(): bool
    {
        return $this->sharingMode() === self::SHARING_CMS_GLOBAL
            && is_string($this->global_scope_key)
            && $this->global_scope_key !== '';
    }

    /** @return HasMany<SaPackageRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(SaPackageRelease::class, 'package_id');
    }
}
