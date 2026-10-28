<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP8 Auth Script:
 * - sa_packages.is_authentication (distinct from is_enabled)
 * - sa_action_tags.auth_script_id / auth_release_id (pinned Auth release; not sa_action_tag_scripts)
 * - sa_account_action_tags M:N entitlement (empty = fail closed; no backfill)
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_packages')
            && ! Schema::connection($conn)->hasColumn('sa_packages', 'is_authentication')) {
            Schema::connection($conn)->table('sa_packages', function (Blueprint $table): void {
                $table->boolean('is_authentication')->default(false)->after('is_enabled');
                $table->index('is_authentication');
            });
        }

        if (Schema::connection($conn)->hasTable('sa_action_tags')) {
            Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table) use ($conn): void {
                if (! Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_script_id')) {
                    $table->unsignedBigInteger('auth_script_id')->nullable()->after('description');
                }
                if (! Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_release_id')) {
                    $table->unsignedBigInteger('auth_release_id')->nullable()->after('auth_script_id');
                }
            });
            // Logical refs to sa_packages.id / sa_package_releases.id — same plane, indexed without FK
            // to avoid migration-order fragility with older tag rows.
            if (! $this->hasIndex($conn, 'sa_action_tags', 'sa_action_tags_auth_script_id_index')) {
                Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table): void {
                    $table->index('auth_script_id');
                });
            }
            if (! $this->hasIndex($conn, 'sa_action_tags', 'sa_action_tags_auth_release_id_index')) {
                Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table): void {
                    $table->index('auth_release_id');
                });
            }
        }

        if (! Schema::connection($conn)->hasTable('sa_account_action_tags')) {
            Schema::connection($conn)->create('sa_account_action_tags', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained('sa_accounts')->cascadeOnDelete();
                $table->foreignId('action_tag_id')->constrained('sa_action_tags')->cascadeOnDelete();
                $table->unsignedBigInteger('granted_by_user_id')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'action_tag_id'], 'sa_account_action_tags_unique');
                $table->index('action_tag_id');
            });
        }
    }

    public function down(): void
    {
        $conn = $this->connection;

        Schema::connection($conn)->dropIfExists('sa_account_action_tags');

        if (Schema::connection($conn)->hasTable('sa_action_tags')) {
            Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table) use ($conn): void {
                $cols = [];
                if (Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_release_id')) {
                    $cols[] = 'auth_release_id';
                }
                if (Schema::connection($conn)->hasColumn('sa_action_tags', 'auth_script_id')) {
                    $cols[] = 'auth_script_id';
                }
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::connection($conn)->hasTable('sa_packages')
            && Schema::connection($conn)->hasColumn('sa_packages', 'is_authentication')) {
            Schema::connection($conn)->table('sa_packages', function (Blueprint $table): void {
                try {
                    $table->dropIndex(['is_authentication']);
                } catch (\Throwable) {
                }
                $table->dropColumn('is_authentication');
            });
        }
    }

    private function hasIndex(string $connection, string $table, string $indexName): bool
    {
        try {
            $sm = Schema::connection($connection)->getConnection()->getSchemaBuilder();
            // Laravel hasIndex available on recent versions; fall back safely.
            if (method_exists($sm, 'hasIndex')) {
                return (bool) $sm->hasIndex($table, $indexName);
            }
        } catch (\Throwable) {
        }

        return false;
    }
};
