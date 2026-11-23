<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaign_action_tags')
            && ! Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
            Schema::connection($this->connection)->table('sa_campaign_action_tags', function (Blueprint $table): void {
                $table->unsignedInteger('interval_minutes')->default(0)->after('sort_order');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaign_action_tags')
            && Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', 'interval_minutes')) {
            Schema::connection($this->connection)->table('sa_campaign_action_tags', function (Blueprint $table): void {
                $table->dropColumn('interval_minutes');
            });
        }
    }
};
