<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\SocialAutomation\Models\Concerns\UsesSocialAutomationConnection;

final class SaJobAttempt extends Model
{
    use UsesSocialAutomationConnection;

    protected $table = 'sa_job_attempts';

    protected $fillable = [
        'attempt_uuid', 'lease_token', 'job_id', 'attempt_no', 'fencing_generation', 'status',
        'agent_id', 'browser_session_id', 'account_id',
        'started_at', 'finished_at', 'lease_expires_at', 'result', 'progress', 'error_code', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'progress' => 'array',
            'attempt_no' => 'integer',
            'fencing_generation' => 'integer',
            'agent_id' => 'integer',
            'browser_session_id' => 'integer',
            'account_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SaJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(SaJob::class, 'job_id');
    }
}
