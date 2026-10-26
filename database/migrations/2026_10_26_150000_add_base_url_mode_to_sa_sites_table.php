<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_sites')) {
            Schema::connection($this->connection)->table('sa_sites', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_sites', 'base_url_mode')) {
                    $table->string('base_url_mode', 16)->default('fixed')->after('name');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_sites', 'test_base_url')) {
                    $table->string('test_base_url', 255)->nullable()->after('base_url');
                }
            });

            // Make base_url nullable for dynamic sites
            Schema::connection($this->connection)->table('sa_sites', function (Blueprint $table): void {
                $table->string('base_url', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_sites')) {
            Schema::connection($this->connection)->table('sa_sites', function (Blueprint $table): void {
                if (Schema::connection($this->connection)->hasColumn('sa_sites', 'test_base_url')) {
                    $table->dropColumn('test_base_url');
                }
                if (Schema::connection($this->connection)->hasColumn('sa_sites', 'base_url_mode')) {
                    $table->dropColumn('base_url_mode');
                }
            });
        }
    }
};
