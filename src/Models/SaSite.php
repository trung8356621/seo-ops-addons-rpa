<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Site registry entity representing a destination platform (e.g. Facebook, X, Reddit).
 *
 * Unified Base URL contract (CP18D.3A):
 * - `base_url` is the single canonical Site URL for preview, path resolution,
 *   Social Account site_url, and Electron Browser navigation.
 * - `test_base_url` is a legacy column retained for compatibility/backfill only;
 *   it is not used at runtime as a permanent fallback.
 * - `base_url_mode` (fixed|dynamic) is preserved; both modes may configure base_url.
 */
class SaSite extends Model
{
    public const MODE_FIXED = 'fixed';
    public const MODE_DYNAMIC = 'dynamic';

    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_sites';

    protected $guarded = [];

    protected $attributes = [
        'base_url_mode' => self::MODE_FIXED,
    ];

    public function isDynamic(): bool
    {
        return ($this->base_url_mode ?? self::MODE_FIXED) === self::MODE_DYNAMIC;
    }

    public function isFixed(): bool
    {
        return ! $this->isDynamic();
    }

    /**
     * Canonical Site Base URL (never invents; never reads test_base_url).
     */
    public function canonicalBaseUrl(): ?string
    {
        $normalized = self::normalizeBaseUrl((string) ($this->base_url ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

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
