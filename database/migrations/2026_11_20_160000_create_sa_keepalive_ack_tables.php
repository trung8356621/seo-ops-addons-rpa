<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Alive Phase 3B.5A — Durable event/artifact ACK cursors for Recovery Nodes.
 * Delivery ACKs only — does not touch Job claim/lease/fencing or Job execution ACK.
 * DO NOT apply to LIVE without a separate operator-approved deploy.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_keepalive_event_ack_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->string('node_id', 128);
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->uuid('campaign_uuid');
            $table->unsignedBigInteger('acked_through_sequence')->default(0);
            $table->unsignedInteger('acked_event_count')->default(0);
            $table->timestamp('last_acked_at')->nullable();
            $table->timestamps();

            $table->unique(['installation_id', 'node_id', 'campaign_id'], 'sa_keepalive_event_ack_node_campaign_unique');
            $table->index(['installation_id', 'campaign_uuid', 'node_id'], 'sa_keepalive_event_ack_tenant_idx');
        });

        Schema::connection($this->connection)->create('sa_keepalive_artifact_acks', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->string('node_id', 128);
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->uuid('campaign_uuid');
            $table->string('job_id', 64)->index();
            $table->unsignedInteger('attempt')->nullable();
            $table->unsignedBigInteger('artifact_id')->nullable()->index();
            $table->uuid('artifact_uuid')->nullable();
            $table->string('storage_key', 512);
            $table->string('sha256', 64);
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['installation_id', 'node_id', 'campaign_id', 'job_id', 'attempt', 'artifact_id'],
                'sa_keepalive_artifact_ack_unique'
            );
            $table->index(['installation_id', 'node_id', 'job_id'], 'sa_keepalive_artifact_ack_node_job_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_artifact_acks');
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_event_ack_cursors');
    }
};
