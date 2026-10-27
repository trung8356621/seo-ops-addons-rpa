<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
            $table->dropUnique(['device_id']);
            $table->string('installation_id', 128)->nullable()->after('device_id')->index();
            $table->unique(['installation_id', 'device_id'], 'sa_agents_installation_device_unique');
        });
        Schema::connection($this->connection)->table('sa_browser_sessions', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('agent_id')->constrained('sa_accounts')->nullOnDelete();
            $table->string('session_state', 24)->default('candidate')->after('ready_status');
            $table->boolean('dispatch_enabled')->default(true)->after('session_state');
            $table->string('dispatch_pause_reason', 64)->nullable()->after('dispatch_enabled');
            $table->timestamp('last_task_at')->nullable()->index();
            $table->unique(['agent_id', 'account_id'], 'sa_browser_agent_account_unique');
            $table->index(['dispatch_enabled', 'session_state'], 'sa_browser_dispatch_state_index');
        });
        Schema::connection($this->connection)->create('sa_account_execution_leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('sa_accounts')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('sa_agents')->cascadeOnDelete();
            $table->foreignId('browser_session_id')->constrained('sa_browser_sessions')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('sa_jobs')->cascadeOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamp('acquired_at');
            $table->timestamp('heartbeat_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
            $table->unique('job_id');
        });
        Schema::connection($this->connection)->create('sa_account_device_affinities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('sa_accounts')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('sa_agents')->cascadeOnDelete();
            $table->timestamp('last_used_at');
            $table->timestamp('affinity_until')->index();
            $table->timestamps();
            $table->unique('account_id');
        });
        Schema::connection($this->connection)->create('sa_client_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_uuid')->unique();
            $table->string('installation_id', 128)->index();
            $table->foreignId('agent_id')->constrained('sa_agents')->cascadeOnDelete();
            $table->foreignId('browser_session_id')->nullable()->constrained('sa_browser_sessions')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('sa_accounts')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('sa_jobs')->nullOnDelete();
            $table->string('type', 32);
            $table->text('message');
            $table->string('idempotency_key', 128);
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'idempotency_key'], 'sa_notification_installation_idempotency');
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_client_notifications');
        Schema::connection($this->connection)->dropIfExists('sa_account_device_affinities');
        Schema::connection($this->connection)->dropIfExists('sa_account_execution_leases');
        Schema::connection($this->connection)->table('sa_browser_sessions', function (Blueprint $table): void {
            $table->dropUnique('sa_browser_agent_account_unique');
            $table->dropIndex('sa_browser_dispatch_state_index');
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['session_state', 'dispatch_enabled', 'dispatch_pause_reason', 'last_task_at']);
        });
        Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
            $table->dropUnique('sa_agents_installation_device_unique');
            $table->dropColumn('installation_id');
            $table->unique('device_id');
        });
    }
};
