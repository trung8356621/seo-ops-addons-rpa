<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $group_uuid
 * @property string $name
 * @property string $scope_type
 * @property string|null $cms_type
 * @property int|null $cms_template_id
 * @property string|null $cms_path_key
 * @property string|null $global_scope_key
 * @property string|null $action_tag_key
 * @property bool $is_enabled
 * @property int|null $created_by_user_id
 */
final class SaScriptVariantGroup extends Model
{
    use UsesSocialAutomationConnection;

    public const SCOPE_CMS_GLOBAL = 'cms_global';

    public const SCOPE_SITE_ACTION = 'site_action';

    protected $table = 'sa_script_variant_groups';

    protected $fillable = [
        'group_uuid',
        'name',
        'scope_type',
        'cms_type',
        'cms_template_id',
        'cms_path_key',
        'global_scope_key',
        'action_tag_key',
        'is_enabled',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cms_template_id' => 'integer',
            'is_enabled' => 'boolean',
            'created_by_user_id' => 'integer',
        ];
    }

    /** @return HasMany<SaScriptVariantGroupItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaScriptVariantGroupItem::class, 'group_id')
            ->orderBy('priority')
            ->orderBy('id');
    }
}
