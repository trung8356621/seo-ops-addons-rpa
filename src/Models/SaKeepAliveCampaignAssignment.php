<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * Explicit Keep Alive Node ↔ Campaign assignment (no auto-balancing).
 *
 * @property int $id
 * @property string $installation_id
 * @property int $keepalive_node_id
 * @property string $node_id
 * @property int $campaign_id
 * @property string $campaign_uuid
 * @property int $version
 */
final class SaKeepAliveCampaignAssignment extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_campaign_assignments';

    protected $fillable = [
        'installation_id',
        'keepalive_node_id',
        'node_id',
        'campaign_id',
        'campaign_uuid',
        'version',
        'assigned_at',
        'assigned_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'keepalive_node_id' => 'integer',
            'campaign_id' => 'integer',
            'version' => 'integer',
            'assigned_by_user_id' => 'integer',
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaKeepAliveNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(SaKeepAliveNode::class, 'keepalive_node_id');
    }

    /** @return BelongsTo<SaCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SaCampaign::class, 'campaign_id');
    }
}
