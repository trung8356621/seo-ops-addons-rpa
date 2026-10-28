<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Action Tag entity connecting Site + Path + Action.
 * Does NOT contain variables, steps or selectors.
 */
class SaActionTag extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_action_tags';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (SaActionTag $tag): void {
            // Synchronize site_key <-> platform, path_key <-> target, action_key <-> action
            if (! empty($tag->site_key) && empty($tag->platform)) {
                $tag->platform = $tag->site_key;
            } elseif (! empty($tag->platform) && empty($tag->site_key)) {
                $tag->site_key = $tag->platform;
            }

            if (! empty($tag->path_key) && empty($tag->target)) {
                $tag->target = $tag->path_key;
            } elseif (! empty($tag->target) && empty($tag->path_key)) {
                $tag->path_key = $tag->target;
            }

            if (! empty($tag->action_key) && empty($tag->action)) {
                $tag->action = $tag->action_key;
            } elseif (! empty($tag->action) && empty($tag->action_key)) {
                $tag->action_key = $tag->action;
            }

            if (empty($tag->tag_key) && ! empty($tag->site_key) && ! empty($tag->path_key) && ! empty($tag->action_key)) {
                $tag->tag_key = self::buildKey($tag->site_key, $tag->path_key, $tag->action_key);
            }
        });
    }

    /**
     * Build stable reference key from site, path, and action keys.
     */
    public static function buildKey(string $site, string $path, string $action): string
    {
        $s = strtolower(trim($site));
        $p = strtolower(trim($path));
        $a = strtolower(trim($action));

        return "{$s}.{$p}.{$a}";
    }

    /**
     * @return BelongsTo<SaSite, SaActionTag>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SaSite::class, 'site_id');
    }

    /**
     * @return BelongsTo<SaPath, SaActionTag>
     */
    public function path(): BelongsTo
    {
        return $this->belongsTo(SaPath::class, 'path_id');
    }

    /**
     * @return BelongsTo<SaAction, SaActionTag>
     */
    public function actionDefinition(): BelongsTo
    {
        return $this->belongsTo(SaAction::class, 'action_id');
    }

    /**
     * @return HasMany<SaScriptRequest>
     */
    public function scriptRequests(): HasMany
    {
        return $this->hasMany(SaScriptRequest::class, 'action_tag_id');
    }

    /**
     * @return HasMany<SaActionTagScript>
     */
    public function scriptLinks(): HasMany
    {
        return $this->hasMany(SaActionTagScript::class, 'action_tag_id');
    }

    /**
     * Pinned Auth Script package (sa_packages.id). Not via sa_action_tag_scripts.
     *
     * @return BelongsTo<SaPackage, SaActionTag>
     */
    public function authScript(): BelongsTo
    {
        return $this->belongsTo(SaPackage::class, 'auth_script_id');
    }

    /**
     * Pinned Auth Script release (sa_package_releases.id).
     *
     * @return BelongsTo<SaPackageRelease, SaActionTag>
     */
    public function authRelease(): BelongsTo
    {
        return $this->belongsTo(SaPackageRelease::class, 'auth_release_id');
    }

    /**
     * @return HasMany<SaAccountActionTag>
     */
    public function accountAuthorizations(): HasMany
    {
        return $this->hasMany(SaAccountActionTag::class, 'action_tag_id');
    }
}
