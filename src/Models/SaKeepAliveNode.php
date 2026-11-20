<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

/**
 * @property int $id
 * @property string $node_id
 * @property string $installation_id
 * @property int|null $agent_id
 * @property string $agent_uuid
 * @property int $client_api_key_id
 * @property string $protocol_version
 * @property int $schema_version
 * @property array|null $capabilities
 * @property int $assignment_generation
 */
final class SaKeepAliveNode extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_nodes';

    protected $fillable = [
        'node_id',
        'installation_id',
        'agent_id',
        'agent_uuid',
        'client_api_key_id',
        'protocol_version',
        'schema_version',
        'capabilities',
        'assignment_generation',
        'registered_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'client_api_key_id' => 'integer',
            'schema_version' => 'integer',
            'capabilities' => 'array',
            'assignment_generation' => 'integer',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<SaKeepAliveCampaignAssignment, $this> */
    public function campaignAssignments(): HasMany
    {
        return $this->hasMany(SaKeepAliveCampaignAssignment::class, 'keepalive_node_id');
    }
}
