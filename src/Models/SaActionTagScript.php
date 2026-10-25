<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Association between Action Tag and Script / Script Release.
 * Supports N-N relationship (one tag can have multiple scripts, one script can implement multiple actions).
 * Allows linking legacy scripts without mutating immutable release manifests.
 *
 * @property int $id
 * @property int $action_tag_id
 * @property string $action_tag_key
 * @property int|null $package_id
 * @property int|null $release_id
 * @property string $script_id
 * @property string|null $script_version
 */
final class SaActionTagScript extends Model
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_action_tag_scripts';

    protected $fillable = [
        'action_tag_id',
        'action_tag_key',
        'package_id',
        'release_id',
        'script_id',
        'script_version',
    ];

    protected function casts(): array
    {
        return [
            'action_tag_id' => 'integer',
            'package_id' => 'integer',
            'release_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SaActionTag, $this> */
    public function actionTag(): BelongsTo
    {
        return $this->belongsTo(SaActionTag::class, 'action_tag_id');
    }

    /** @return BelongsTo<SaPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(SaPackage::class, 'package_id');
    }

    /** @return BelongsTo<SaPackageRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(SaPackageRelease::class, 'release_id');
    }
}
