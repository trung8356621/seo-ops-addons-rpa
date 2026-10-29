<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Script Request entity tracking SEO-OPS work assigned to RPA Admin.
 */
class SaScriptRequest extends Model
{
    public const TYPE_CREATE = 'create';
    public const TYPE_FIX = 'fix';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEVELOPING = 'developing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_NEEDS_FIX = 'needs_fix';

    /**
     * Statuses that block creating a duplicate TYPE_CREATE Script Request
     * for the same action tag (idempotency window in storeTag).
     *
     * @return list<string>
     */
    public static function openCreateRequestStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_DEVELOPING,
            self::STATUS_PUBLISHED,
        ];
    }

    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    protected $table = 'sa_script_requests';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'path_inputs' => 'array',
    ];

    /**
     * @return BelongsTo<SaActionTag, SaScriptRequest>
     */
    public function actionTag(): BelongsTo
    {
        return $this->belongsTo(SaActionTag::class, 'action_tag_id');
    }
}
