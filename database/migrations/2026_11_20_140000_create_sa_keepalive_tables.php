<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Alive Phase 3B.1 — Node registration + explicit Campaign assignments.
 * Additive only. Does not touch Job claim/lease/fencing tables.
 * DO NOT apply to LIVE without a separate operator-approved deploy.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_keepalive_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 128);
            $table->string('installation_id', 128)->index();
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->string('agent_uuid', 64)->index();
            $table->unsignedBigInteger('client_api_key_id')->index();
            $table->string('protocol_version', 32);
            $table->unsignedInteger('schema_version')->default(2);
            $table->json('capabilities')->nullable();
            $table->unsignedInteger('assignment_generation')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['installation_id', 'node_id'], 'sa_keepalive_nodes_tenant_node_unique');
            $table->index(['installation_id', 'client_api_key_id'], 'sa_keepalive_nodes_tenant_key_idx');
        });

        Schema::connection($this->connection)->create('sa_keepalive_campaign_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->foreignId('keepalive_node_id')->constrained('sa_keepalive_nodes')->cascadeOnDelete();
            $table->string('node_id', 128);
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->uuid('campaign_uuid');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['keepalive_node_id', 'campaign_id'], 'sa_keepalive_node_campaign_unique');
            $table->index(['installation_id', 'node_id'], 'sa_keepalive_assign_tenant_node_idx');
            $table->index(['campaign_id', 'node_id'], 'sa_keepalive_assign_campaign_node_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_campaign_assignments');
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_nodes');
    }
};
