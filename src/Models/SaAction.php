<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Action registry entity representing a reusable operation (e.g. Post, Comment, Collect).
 */
class SaAction extends Model
{
    public const ACTION_POST = 'post';
    public const ACTION_COMMENT = 'comment';
    public const ACTION_COLLECT = 'collect';

    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_actions';

    protected $guarded = [];

    /**
     * @return HasMany<SaActionTag>
     */
    public function actionTags(): HasMany
    {
        return $this->hasMany(SaActionTag::class, 'action_id');
    }
}
