<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Add site_url to sa_accounts for WordPress and custom platform sites.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'site_url')) {
                    $table->string('site_url', 500)->nullable()->after('email');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                if (Schema::connection($this->connection)->hasColumn('sa_accounts', 'site_url')) {
                    $table->dropColumn('site_url');
                }
            });
        }
    }
};
