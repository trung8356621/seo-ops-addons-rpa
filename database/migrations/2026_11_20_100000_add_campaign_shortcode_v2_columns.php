<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign Shortcode V2 — additive columns only.
 * Do not run on VPS without explicit approval.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaigns')) {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_campaigns', 'shortcode_mapping')) {
                    $table->json('shortcode_mapping')->nullable()->after('meta');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaigns', 'shortcode_mapping_version')) {
                    $table->unsignedInteger('shortcode_mapping_version')->default(0)->after('shortcode_mapping');
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_campaign_contents')) {
            Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'source_format')) {
                    $table->string('source_format', 32)->default('legacy_input')->after('input');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'raw_source')) {
                    $table->longText('raw_source')->nullable()->after('source_format');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'mapping_version')) {
                    $table->unsignedInteger('mapping_version')->nullable()->after('raw_source');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaign_contents')) {
            Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                foreach (['mapping_version', 'raw_source', 'source_format'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_campaign_contents', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_campaigns')) {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                foreach (['shortcode_mapping_version', 'shortcode_mapping'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_campaigns', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
