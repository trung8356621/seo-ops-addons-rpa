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

            $actionKey = $tag->action_key !== null ? trim((string) $tag->action_key) : '';
            $tag->action_key = $actionKey !== '' ? $actionKey : null;

            $legacyAction = $tag->action !== null ? trim((string) $tag->action) : '';
            if ($actionKey !== '' && $legacyAction === '') {
                $tag->action = $actionKey;
            } elseif ($legacyAction !== '' && $actionKey === '') {
                $tag->action_key = $legacyAction;
            } elseif ($tag->action === null) {
                // sa_action_tags.action is NOT NULL in base schema; keep empty string for two-part tags.
                $tag->action = '';
            }

            if (empty($tag->tag_key) && ! empty($tag->site_key) && ! empty($tag->path_key)) {
                $tag->tag_key = self::buildKey($tag->site_key, $tag->path_key, $tag->action_key);
            }
        });
    }

    /**
     * Resolve effective action_key (null for two-part Site + Path tags).
     */
    public function resolvedActionKey(): ?string
    {
        $actionKey = $this->action_key !== null ? trim((string) $this->action_key) : '';
        if ($actionKey !== '') {
            return $actionKey;
        }

        $legacyAction = $this->action !== null ? trim((string) $this->action) : '';

        return $legacyAction !== '' ? $legacyAction : null;
    }

    /**
     * Build stable reference key from site, path, and optional action keys.
     */
    public static function buildKey(string $site, string $path, ?string $action = null): string
    {
        $s = strtolower(trim($site));
        $p = strtolower(trim($path));
        $a = $action !== null ? strtolower(trim($action)) : '';

        if ($a === '') {
            return "{$s}.{$p}";
        }

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
