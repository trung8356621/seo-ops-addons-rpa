<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaKeepAliveArtifactAck extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_keepalive_artifact_acks';

    protected $fillable = [
        'installation_id', 'node_id', 'campaign_id', 'campaign_uuid',
        'job_id', 'attempt', 'artifact_id', 'artifact_uuid',
        'storage_key', 'sha256', 'acked_at',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'attempt' => 'integer',
            'artifact_id' => 'integer',
            'acked_at' => 'datetime',
        ];
    }
}
