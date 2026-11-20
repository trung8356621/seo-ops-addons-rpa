<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Alive Phase 3B.3 — Campaign snapshot/event feed persistence.
 *
 * sa_job_events lacks campaign-scoped monotonic sequence; this additive store
 * projects durable lifecycle events with stable event_sequence.
 * Does not modify Job claim/lease/fencing tables or Orchestrator write paths.
 * DO NOT apply to LIVE without a separate operator-approved deploy.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_keepalive_nodes')) {
            Schema::connection($this->connection)->table('sa_keepalive_nodes', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_keepalive_nodes', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable()->after('last_seen_at');
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_keepalive_campaign_assignments')) {
            Schema::connection($this->connection)->table('sa_keepalive_campaign_assignments', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_keepalive_campaign_assignments', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable()->after('assigned_by_user_id')->index();
                }
            });
        }

        Schema::connection($this->connection)->create('sa_keepalive_campaign_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->uuid('campaign_uuid');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('snapshot_version')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id'], 'sa_keepalive_campaign_seq_unique');
            $table->index(['installation_id', 'campaign_uuid'], 'sa_keepalive_campaign_seq_tenant_idx');
        });

        Schema::connection($this->connection)->create('sa_keepalive_campaign_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('installation_id', 128)->index();
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->uuid('campaign_uuid');
            $table->string('job_id', 64)->nullable()->index();
            $table->unsignedInteger('attempt')->nullable();
            $table->unsignedInteger('fencing_generation')->default(0);
            $table->unsignedBigInteger('event_sequence');
            $table->timestamp('occurred_at')->index();
            $table->unsignedInteger('payload_version')->default(2);
            $table->string('event_type', 64)->index();
            $table->string('source_kind', 32); // job_event|artifact
            $table->unsignedBigInteger('source_id');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['source_kind', 'source_id'], 'sa_keepalive_events_source_unique');
            $table->unique(['campaign_id', 'event_sequence'], 'sa_keepalive_events_campaign_seq_unique');
            $table->index(['campaign_id', 'event_sequence'], 'sa_keepalive_events_feed_idx');
            $table->index(['installation_id', 'campaign_uuid', 'event_sequence'], 'sa_keepalive_events_tenant_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_campaign_events');
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_campaign_sequences');

        if (Schema::connection($this->connection)->hasTable('sa_keepalive_campaign_assignments')
            && Schema::connection($this->connection)->hasColumn('sa_keepalive_campaign_assignments', 'revoked_at')) {
            Schema::connection($this->connection)->table('sa_keepalive_campaign_assignments', function (Blueprint $table): void {
                $table->dropColumn('revoked_at');
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_keepalive_nodes')
            && Schema::connection($this->connection)->hasColumn('sa_keepalive_nodes', 'revoked_at')) {
            Schema::connection($this->connection)->table('sa_keepalive_nodes', function (Blueprint $table): void {
                $table->dropColumn('revoked_at');
            });
        }
    }
};
