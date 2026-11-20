<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Alive Phase 3B.6C — advisory notify outbox (crash-safe, post-commit publish).
 * Additive only. Do not apply to LIVE without approval. Never blocks Job transactions.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_keepalive_notify_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->string('campaign_uuid', 64)->index();
            $table->string('event_type', 64);
            $table->string('coalesce_key', 191);
            $table->unsignedInteger('seq_hint')->nullable();
            $table->unsignedInteger('route_generation')->nullable();
            $table->unsignedInteger('assignment_generation')->nullable();
            $table->json('rooms');
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending|published|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['coalesce_key', 'status'], 'sa_ka_outbox_coalesce_idx');
            $table->index(['status', 'available_at'], 'sa_ka_outbox_flush_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_notify_outbox');
    }
};
