<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Add last_interaction_at to sa_accounts for RPA execution tracking.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')
            && ! Schema::connection($this->connection)->hasColumn('sa_accounts', 'last_interaction_at')
        ) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                $table->timestamp('last_interaction_at')->nullable()->after('last_confirmed_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')
            && Schema::connection($this->connection)->hasColumn('sa_accounts', 'last_interaction_at')
        ) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                $table->dropColumn('last_interaction_at');
            });
        }
    }
};
