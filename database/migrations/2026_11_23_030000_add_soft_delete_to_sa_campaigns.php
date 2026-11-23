<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive soft-delete metadata on sa_campaigns.
 * Preserves all historical snapshots, contents, jobs, attempts, leases, and artifacts.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_campaigns')) {
            return;
        }

        Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('sa_campaigns', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('completed_at')->index();
            }
            if (! Schema::connection($this->connection)->hasColumn('sa_campaigns', 'deleted_by_user_id')) {
                $table->unsignedBigInteger('deleted_by_user_id')->nullable()->after('deleted_at');
            }
        });

        try {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                $table->index(['installation_id', 'deleted_at'], 'sa_campaigns_install_deleted_idx');
            });
        } catch (\Throwable) {
            // Index already exists or driver does not support duplicate-safe check.
        }
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_campaigns')) {
            return;
        }

        try {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                $table->dropIndex('sa_campaigns_install_deleted_idx');
            });
        } catch (\Throwable) {
        }

        Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('sa_campaigns', 'deleted_by_user_id')) {
                $table->dropColumn('deleted_by_user_id');
            }
            if (Schema::connection($this->connection)->hasColumn('sa_campaigns', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
