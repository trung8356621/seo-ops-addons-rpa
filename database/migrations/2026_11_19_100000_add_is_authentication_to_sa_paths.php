<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Authentication Path flag on sa_paths (distinct from Action Tag auth pins / Script is_authentication).
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_paths')
            && ! Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication')) {
            Schema::connection($conn)->table('sa_paths', function (Blueprint $table): void {
                $table->boolean('is_authentication')->default(false)->after('description');
                $table->index('is_authentication');
            });
        }
    }

    public function down(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_paths')
            && Schema::connection($conn)->hasColumn('sa_paths', 'is_authentication')) {
            Schema::connection($conn)->table('sa_paths', function (Blueprint $table): void {
                try {
                    $table->dropIndex(['is_authentication']);
                } catch (\Throwable) {
                    // Index name may differ across drivers; column drop still required.
                }
                $table->dropColumn('is_authentication');
            });
        }
    }
};
