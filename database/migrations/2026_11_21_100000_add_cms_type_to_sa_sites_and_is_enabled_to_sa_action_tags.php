<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Add persistent cms_type to sa_sites and is_enabled toggle to sa_action_tags.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_sites')
            && ! Schema::connection($conn)->hasColumn('sa_sites', 'cms_type')) {
            Schema::connection($conn)->table('sa_sites', function (Blueprint $table): void {
                $table->string('cms_type', 32)->nullable()->after('base_url_mode');
                $table->index('cms_type');
            });
        }

        if (Schema::connection($conn)->hasTable('sa_action_tags')
            && ! Schema::connection($conn)->hasColumn('sa_action_tags', 'is_enabled')) {
            Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table): void {
                $table->boolean('is_enabled')->default(true)->after('description');
                $table->index('is_enabled');
            });
        }
    }

    public function down(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_action_tags')
            && Schema::connection($conn)->hasColumn('sa_action_tags', 'is_enabled')) {
            Schema::connection($conn)->table('sa_action_tags', function (Blueprint $table): void {
                try {
                    $table->dropIndex(['is_enabled']);
                } catch (\Throwable) {
                }
                $table->dropColumn('is_enabled');
            });
        }

        if (Schema::connection($conn)->hasTable('sa_sites')
            && Schema::connection($conn)->hasColumn('sa_sites', 'cms_type')) {
            Schema::connection($conn)->table('sa_sites', function (Blueprint $table): void {
                try {
                    $table->dropIndex(['cms_type']);
                } catch (\Throwable) {
                }
                $table->dropColumn('cms_type');
            });
        }
    }
};
