<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational Script configuration columns on sa_packages (language_label, is_primary).
 * Additive only; preserves immutable sa_package_releases and existing sa_packages_global_scope_unique.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_packages')) {
            return;
        }

        Schema::connection($this->connection)->table('sa_packages', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('sa_packages', 'language_label')) {
                $table->string('language_label', 32)->nullable()->after('name')->index();
            }
            if (! Schema::connection($this->connection)->hasColumn('sa_packages', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('is_enabled')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_packages')) {
            return;
        }

        Schema::connection($this->connection)->table('sa_packages', function (Blueprint $table): void {
            foreach (['is_primary', 'language_label'] as $col) {
                if (Schema::connection($this->connection)->hasColumn('sa_packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
