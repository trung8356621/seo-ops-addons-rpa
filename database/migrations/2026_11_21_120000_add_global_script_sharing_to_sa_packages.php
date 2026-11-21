<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Add server-managed Global Script sharing configuration to sa_packages.
 * Default is 'site_specific'; 'cms_global' is scoped by CMS Type + CMS Path Template.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $conn = $this->connection;

        if (! Schema::connection($conn)->hasTable('sa_packages')) {
            return;
        }

        Schema::connection($conn)->table('sa_packages', function (Blueprint $table) use ($conn): void {
            if (! Schema::connection($conn)->hasColumn('sa_packages', 'sharing_mode')) {
                $table->string('sharing_mode', 32)->default('site_specific')->after('is_authentication');
                $table->index('sharing_mode', 'sa_packages_sharing_mode_idx');
            }
            if (! Schema::connection($conn)->hasColumn('sa_packages', 'global_cms_type')) {
                $table->string('global_cms_type', 64)->nullable()->after('sharing_mode');
            }
            if (! Schema::connection($conn)->hasColumn('sa_packages', 'global_cms_template_id')) {
                $table->unsignedBigInteger('global_cms_template_id')->nullable()->after('global_cms_type');
            }
            if (! Schema::connection($conn)->hasColumn('sa_packages', 'global_cms_path_key')) {
                $table->string('global_cms_path_key', 64)->nullable()->after('global_cms_template_id');
            }
            if (! Schema::connection($conn)->hasColumn('sa_packages', 'global_scope_key')) {
                $table->string('global_scope_key', 191)->nullable()->after('global_cms_path_key');
                $table->unique('global_scope_key', 'sa_packages_global_scope_unique');
            }
        });
    }

    public function down(): void
    {
        $conn = $this->connection;

        if (! Schema::connection($conn)->hasTable('sa_packages')) {
            return;
        }

        Schema::connection($conn)->table('sa_packages', function (Blueprint $table) use ($conn): void {
            if (Schema::connection($conn)->hasColumn('sa_packages', 'global_scope_key')) {
                try {
                    $table->dropUnique('sa_packages_global_scope_unique');
                } catch (\Throwable) {
                }
                $table->dropColumn('global_scope_key');
            }
            foreach (['global_cms_path_key', 'global_cms_template_id', 'global_cms_type'] as $col) {
                if (Schema::connection($conn)->hasColumn('sa_packages', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::connection($conn)->hasColumn('sa_packages', 'sharing_mode')) {
                try {
                    $table->dropIndex('sa_packages_sharing_mode_idx');
                } catch (\Throwable) {
                }
                $table->dropColumn('sharing_mode');
            }
        });
    }
};
