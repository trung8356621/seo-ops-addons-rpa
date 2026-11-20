<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Alive Phase 3B.6B — Socket ticket route_generation + jti replay window.
 * Additive only. No Job claim/lease changes. Do not apply to LIVE without approval.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_keepalive_route_states', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128)->index();
            $table->string('subject_type', 32); // node|executor
            $table->string('subject_key', 128);
            $table->unsignedInteger('route_generation')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['installation_id', 'subject_type', 'subject_key'],
                'sa_keepalive_route_subject_unique'
            );
        });

        Schema::connection($this->connection)->create('sa_keepalive_socket_ticket_jtis', function (Blueprint $table): void {
            $table->id();
            $table->string('jti', 64)->unique();
            $table->string('installation_id', 128)->index();
            $table->unsignedBigInteger('client_api_key_id')->nullable()->index();
            $table->string('role', 32);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at'], 'sa_keepalive_jti_consume_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_socket_ticket_jtis');
        Schema::connection($this->connection)->dropIfExists('sa_keepalive_route_states');
    }
};
