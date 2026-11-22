<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent Article Library + Campaign Article Assignment & Immutable Snapshot (additive only).
 * Preserves all existing sa_campaigns, sa_campaign_contents, sa_jobs, and sa_job_attempts rows.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_articles')) {
            Schema::connection($this->connection)->create('sa_articles', function (Blueprint $table): void {
                $table->id();
                $table->uuid('article_uuid')->unique();
                $table->string('installation_id', 128)->index();
                $table->string('title', 255);
                $table->string('external_ref', 191)->nullable();
                $table->unsignedBigInteger('action_tag_id')->index();
                $table->string('action_tag_key', 191)->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('site_key', 128)->nullable()->index();
                $table->string('status', 32)->default('ready')->index();
                $table->string('source_format', 32)->default('legacy_input');
                $table->longText('raw_source')->nullable();
                $table->json('input')->nullable();
                $table->json('shortcode_mapping')->nullable();
                $table->unsignedInteger('mapping_version')->default(0);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['installation_id', 'status'], 'sa_articles_inst_status_idx');
                $table->index(['installation_id', 'action_tag_id'], 'sa_articles_inst_tag_idx');
                $table->index(['installation_id', 'site_key'], 'sa_articles_inst_site_idx');
                $table->index(['installation_id', 'external_ref'], 'sa_articles_inst_extref_idx');
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_campaign_contents')) {
            Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'article_id')) {
                    $table->unsignedBigInteger('article_id')->nullable()->after('campaign_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'article_uuid')) {
                    $table->uuid('article_uuid')->nullable()->after('article_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'snapshot_at')) {
                    $table->timestamp('snapshot_at')->nullable()->after('mapping_version');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_contents', 'snapshot_payload')) {
                    $table->json('snapshot_payload')->nullable()->after('snapshot_at');
                }
            });

            try {
                Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                    $table->unique(['campaign_id', 'article_id'], 'sa_cc_campaign_article_unique');
                });
            } catch (\Throwable) {
                // Index may already exist on re-run.
            }
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaign_contents')) {
            try {
                Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                    $table->dropUnique('sa_cc_campaign_article_unique');
                });
            } catch (\Throwable) {
                // Ignore if index is absent.
            }

            Schema::connection($this->connection)->table('sa_campaign_contents', function (Blueprint $table): void {
                foreach (['snapshot_payload', 'snapshot_at', 'article_uuid', 'article_id'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_campaign_contents', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::connection($this->connection)->dropIfExists('sa_articles');
    }
};
