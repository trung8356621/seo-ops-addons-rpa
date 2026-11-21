<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign V2 — Multi-Tag Draft Persistence (additive only).
 * Adds plan_version to sa_campaigns and creates sa_campaign_action_tags + sa_campaign_content_assignments.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaigns')
            && ! Schema::connection($this->connection)->hasColumn('sa_campaigns', 'plan_version')) {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                $table->unsignedTinyInteger('plan_version')->default(1)->after('status')->index();
            });
        }

        if (! Schema::connection($this->connection)->hasTable('sa_campaign_action_tags')) {
            Schema::connection($this->connection)->create('sa_campaign_action_tags', function (Blueprint $table): void {
                $table->id();
                $table->uuid('plan_tag_uuid')->unique();
                $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
                $table->unsignedBigInteger('action_tag_id')->index();
                $table->string('action_tag_key', 191);
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('site_key', 128);
                $table->string('cms_type', 64)->nullable();
                $table->string('platform', 64)->nullable();
                $table->unsignedBigInteger('package_id')->index();
                $table->string('script_id', 128);
                $table->string('script_name', 255)->nullable();
                $table->unsignedBigInteger('release_id')->index();
                $table->string('script_version', 64);
                $table->string('script_content_digest', 64)->nullable();
                $table->string('script_execution_digest', 64)->nullable();
                $table->string('resolution_source', 32);
                $table->string('source_action_tag_key', 191)->nullable();
                $table->json('cms_scope')->nullable();
                $table->json('manifest_snapshot')->nullable();
                $table->json('shortcode_mapping')->nullable();
                $table->unsignedInteger('shortcode_mapping_version')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['campaign_id', 'action_tag_key'], 'sa_campaign_action_tags_campaign_tag_key_unique');
                $table->unique(['campaign_id', 'action_tag_id'], 'sa_campaign_action_tags_campaign_tag_id_unique');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('sa_campaign_content_assignments')) {
            Schema::connection($this->connection)->create('sa_campaign_content_assignments', function (Blueprint $table): void {
                $table->id();
                $table->uuid('assignment_uuid')->unique();
                $table->foreignId('campaign_id')->constrained('sa_campaigns')->cascadeOnDelete();
                $table->foreignId('campaign_content_id')->constrained('sa_campaign_contents')->cascadeOnDelete();
                $table->foreignId('campaign_action_tag_id')->constrained('sa_campaign_action_tags')->cascadeOnDelete();
                $table->unsignedBigInteger('package_id');
                $table->unsignedBigInteger('release_id');
                $table->string('script_id', 128);
                $table->string('script_version', 64);
                $table->string('source_format', 32)->default('shortcode');
                $table->json('shortcode_mapping')->nullable();
                $table->unsignedInteger('mapping_version')->default(0);
                $table->json('input')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->timestamps();

                $table->unique(['campaign_content_id', 'campaign_action_tag_id'], 'sa_cc_assign_content_tag_unique');
                $table->index(['campaign_id', 'campaign_action_tag_id'], 'sa_cc_assign_campaign_tag_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_campaign_content_assignments');
        Schema::connection($this->connection)->dropIfExists('sa_campaign_action_tags');

        if (Schema::connection($this->connection)->hasTable('sa_campaigns')
            && Schema::connection($this->connection)->hasColumn('sa_campaigns', 'plan_version')) {
            Schema::connection($this->connection)->table('sa_campaigns', function (Blueprint $table): void {
                $table->dropColumn('plan_version');
            });
        }
    }
};
