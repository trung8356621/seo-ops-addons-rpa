<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP19A — Campaign / Content / Job attempt extensions (additive).
 * Does not drop existing Task/Job tables. task_id becomes nullable for campaign jobs.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('campaign_uuid')->unique();
            $table->string('installation_id', 128)->index();
            $table->string('name', 255);
            $table->string('platform', 64)->index();
            $table->string('execution_mode', 32); // one_account_one_job|one_account_many_jobs
            $table->string('run_mode', 16)->default('live'); // test|live — independent of execution_mode
            $table->string('status', 32)->default('draft')->index();
            $table->index(['run_mode', 'status']);
            $table->string('script_id', 128);
            $table->string('script_version', 64);
            $table->string('script_content_digest', 64)->nullable();
            $table->unsignedInteger('resource_recheck_seconds')->default(3600);
            $table->timestamp('next_recheck_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['installation_id', 'status']);
            $table->index(['script_id', 'script_version']);
        });

        Schema::connection($this->connection)->create('sa_campaign_contents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_uuid')->unique();
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->string('external_ref', 191)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('input')->nullable();
            $table->foreignId('assigned_account_id')->nullable()->constrained('sa_accounts')->nullOnDelete();
            $table->foreignId('current_job_id')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('last_error_meta')->nullable();
            $table->string('admin_action_idempotency', 64)->nullable();
            $table->timestamp('admin_action_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->unique(['campaign_id', 'external_ref'], 'sa_campaign_contents_campaign_external_unique');
        });

        Schema::connection($this->connection)->create('sa_campaign_account_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('sa_accounts')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('sa_campaign_contents')->cascadeOnDelete();
            $table->string('reason', 64)->default('dispatch');
            $table->timestamps();

            $table->unique(['campaign_id', 'account_id'], 'sa_campaign_account_unique');
            $table->index(['campaign_id', 'content_id']);
        });

        if (Schema::connection($this->connection)->hasTable('sa_jobs')) {
            Schema::connection($this->connection)->table('sa_jobs', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_jobs', 'campaign_id')) {
                    $table->unsignedBigInteger('campaign_id')->nullable()->after('task_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_jobs', 'campaign_content_id')) {
                    $table->unsignedBigInteger('campaign_content_id')->nullable()->after('campaign_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_jobs', 'lease_token')) {
                    $table->string('lease_token', 64)->nullable()->after('idempotency_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_jobs', 'fencing_generation')) {
                    $table->unsignedInteger('fencing_generation')->default(0)->after('lease_token');
                }
            });

            // Make task_id nullable for campaign-origin jobs (MySQL).
            try {
                $driver = Schema::connection($this->connection)->getConnection()->getDriverName();
                if ($driver === 'mysql') {
                    DB::connection($this->connection)->statement(
                        'ALTER TABLE sa_jobs MODIFY task_id BIGINT UNSIGNED NULL'
                    );
                } elseif ($driver === 'pgsql') {
                    DB::connection($this->connection)->statement(
                        'ALTER TABLE sa_jobs ALTER COLUMN task_id DROP NOT NULL'
                    );
                }
            } catch (\Throwable) {
                // SQLite / already-nullable / no DDL rights — schema gate reports readiness.
            }
        }

        if (Schema::connection($this->connection)->hasTable('sa_job_attempts')) {
            Schema::connection($this->connection)->table('sa_job_attempts', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'lease_token')) {
                    $table->string('lease_token', 64)->nullable()->after('attempt_uuid');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'lease_expires_at')) {
                    $table->timestamp('lease_expires_at')->nullable()->after('finished_at');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'agent_id')) {
                    $table->unsignedBigInteger('agent_id')->nullable()->after('job_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'browser_session_id')) {
                    $table->unsignedBigInteger('browser_session_id')->nullable()->after('agent_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'account_id')) {
                    $table->unsignedBigInteger('account_id')->nullable()->after('browser_session_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'progress')) {
                    $table->json('progress')->nullable()->after('result');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'error_message')) {
                    $table->text('error_message')->nullable()->after('error_code');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_job_attempts', 'fencing_generation')) {
                    $table->unsignedInteger('fencing_generation')->default(0)->after('attempt_no');
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_account_execution_leases')) {
            Schema::connection($this->connection)->table('sa_account_execution_leases', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_account_execution_leases', 'lease_token')) {
                    $table->string('lease_token', 64)->nullable()->after('job_id');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_account_execution_leases', 'attempt_id')) {
                    $table->unsignedBigInteger('attempt_id')->nullable()->after('lease_token')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_account_execution_leases', 'fencing_generation')) {
                    $table->unsignedInteger('fencing_generation')->default(0)->after('attempt_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_account_execution_leases')) {
            Schema::connection($this->connection)->table('sa_account_execution_leases', function (Blueprint $table): void {
                foreach (['fencing_generation', 'attempt_id', 'lease_token'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_account_execution_leases', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_job_attempts')) {
            Schema::connection($this->connection)->table('sa_job_attempts', function (Blueprint $table): void {
                foreach ([
                    'fencing_generation', 'error_message', 'progress', 'account_id',
                    'browser_session_id', 'agent_id', 'lease_expires_at', 'lease_token',
                ] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_job_attempts', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_jobs')) {
            Schema::connection($this->connection)->table('sa_jobs', function (Blueprint $table): void {
                foreach (['fencing_generation', 'lease_token', 'campaign_content_id', 'campaign_id'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::connection($this->connection)->dropIfExists('sa_campaign_account_assignments');
        Schema::connection($this->connection)->dropIfExists('sa_campaign_contents');
        Schema::connection($this->connection)->dropIfExists('sa_campaigns');
    }
};
