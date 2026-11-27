<?php

declare(strict_types=1);

/** READ-ONLY: Job/Attempt/Event/Artifact inspection for duplicate history. No writes. */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$db = DB::connection('omi_social_automation');
$jobUuid = 'e725b1da-c4b1-4cf1-a229-5f9f9aff6516';
$campaignUuid = 'd70d1999-5c71-46f8-a3a4-b0f04014aa46';

$campaign = $db->table('sa_campaigns')->where('campaign_uuid', $campaignUuid)->first();
$job = $db->table('sa_jobs')->where('job_uuid', $jobUuid)->first();

$out = [
    'probe' => 'p1_duplicate_attempt_diag_ro',
    'mutated' => false,
    'now' => now()->toIso8601String(),
];

if (! $job) {
    $out['error'] = 'job_not_found';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

$attempts = $db->table('sa_job_attempts')->where('job_id', $job->id)->orderBy('id')->get();
$events = $db->table('sa_job_events')->where('job_id', $job->id)->orderBy('id')->get();
$artifacts = Schema::connection('omi_social_automation')->hasTable('sa_artifacts')
    ? $db->table('sa_artifacts')->where('job_id', $job->id)->orderBy('id')->get()
    : collect();

$attemptRows = [];
foreach ($attempts as $a) {
    $arts = $artifacts->where('attempt_id', $a->id)->values();
    $attemptRows[] = [
        'id' => (int) $a->id,
        'attempt_uuid' => $a->attempt_uuid,
        'attempt_no' => (int) $a->attempt_no,
        'status' => $a->status,
        'fencing_generation' => $a->fencing_generation ?? null,
        'lease_token_prefix' => isset($a->lease_token) ? substr((string) $a->lease_token, 0, 8) : null,
        'agent_id' => $a->agent_id ?? null,
        'browser_session_id' => $a->browser_session_id ?? null,
        'account_id' => $a->account_id ?? null,
        'started_at' => $a->started_at ?? null,
        'finished_at' => $a->finished_at ?? null,
        'error_code' => $a->error_code ?? null,
        'error_message' => $a->error_message ?? null,
        'has_result' => $a->result !== null && $a->result !== '',
        'result_preview' => is_string($a->result) ? substr($a->result, 0, 300) : null,
        'created_at' => $a->created_at ?? null,
        'updated_at' => $a->updated_at ?? null,
        'artifact_count' => $arts->count(),
        'artifacts' => $arts->map(static fn ($x): array => [
            'id' => (int) $x->id,
            'artifact_uuid' => $x->artifact_uuid ?? null,
            'kind' => $x->kind ?? null,
            'mime' => $x->mime ?? null,
            'size_bytes' => $x->size_bytes ?? null,
            'checksum_sha256' => $x->checksum_sha256 ?? null,
            'created_at' => $x->created_at ?? null,
        ])->all(),
    ];
}

$payload = json_decode((string) ($job->payload ?? '{}'), true);
$fence = is_array($payload) ? ($payload['_fence'] ?? null) : null;

$out['campaign'] = $campaign ? [
    'id' => (int) $campaign->id,
    'uuid' => $campaign->campaign_uuid,
    'name' => $campaign->name ?? null,
    'status' => $campaign->status,
] : null;
$out['job'] = [
    'id' => (int) $job->id,
    'job_uuid' => $job->job_uuid,
    'status' => $job->status,
    'campaign_id' => $job->campaign_id,
    'campaign_content_id' => $job->campaign_content_id,
    'agent_id' => $job->agent_id,
    'browser_session_id' => $job->browser_session_id,
    'account_id' => $job->account_id,
    'reserved_at' => $job->reserved_at ?? null,
    'claimed_at' => $job->claimed_at ?? null,
    'started_at' => $job->started_at ?? null,
    'finished_at' => $job->finished_at ?? null,
    'lease_expires_at' => $job->lease_expires_at ?? null,
    'error_code' => $job->error_code ?? null,
    'fencing_generation' => $job->fencing_generation ?? null,
    'fence' => $fence,
    'result_preview' => is_string($job->result) ? substr($job->result, 0, 400) : null,
];
$out['attempt_count'] = $attempts->count();
$out['attempts'] = $attemptRows;
$out['events'] = $events->map(static fn ($e): array => [
    'id' => (int) $e->id,
    'attempt_id' => $e->attempt_id,
    'event_type' => $e->event_type,
    'from_status' => $e->from_status,
    'to_status' => $e->to_status,
    'occurred_at' => $e->occurred_at,
    'payload_preview' => is_string($e->payload ?? null) ? substr((string) $e->payload, 0, 200) : null,
])->all();
$out['artifact_total'] = $artifacts->count();
$out['attempt_no_counts'] = $attempts->groupBy('attempt_no')->map->count();
$out['unique_indexes_hint'] = [
    'sa_job_attempts_unique_check' => 'see migrations',
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
