<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $variant_uuid
 * @property int $group_id
 * @property int $package_id
 * @property string $package_key
 * @property int $release_id
 * @property string $script_version
 * @property string|null $content_digest
 * @property string|null $execution_digest
 * @property string $language_label
 * @property int $priority
 * @property bool $is_enabled
 */
final class SaScriptVariantGroupItem extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_script_variant_group_items';

    protected $fillable = [
        'variant_uuid',
        'group_id',
        'package_id',
        'package_key',
        'release_id',
        'script_version',
        'content_digest',
        'execution_digest',
        'language_label',
        'priority',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'package_id' => 'integer',
            'release_id' => 'integer',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<SaScriptVariantGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SaScriptVariantGroup::class, 'group_id');
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
