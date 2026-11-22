<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Script Variant Groups & Ordered Fallback Pinning (additive only).
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_script_variant_groups')) {
            Schema::connection($this->connection)->create('sa_script_variant_groups', function (Blueprint $table): void {
                $table->id();
                $table->uuid('group_uuid')->unique();
                $table->string('name', 191);
                $table->string('scope_type', 32)->default('cms_global')->index(); // cms_global|site_action
                $table->string('cms_type', 64)->nullable()->index();
                $table->unsignedBigInteger('cms_template_id')->nullable()->index();
                $table->string('cms_path_key', 128)->nullable()->index();
                $table->string('global_scope_key', 191)->nullable()->unique();
                $table->string('action_tag_key', 191)->nullable()->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::connection($this->connection)->hasTable('sa_script_variant_group_items')) {
            Schema::connection($this->connection)->create('sa_script_variant_group_items', function (Blueprint $table): void {
                $table->id();
                $table->uuid('variant_uuid')->unique();
                $table->foreignId('group_id')->constrained('sa_script_variant_groups')->cascadeOnDelete();
                $table->unsignedBigInteger('package_id')->index();
                $table->string('package_key', 191)->index();
                $table->unsignedBigInteger('release_id')->index();
                $table->string('script_version', 64);
                $table->string('content_digest', 64)->nullable();
                $table->string('execution_digest', 64)->nullable();
                $table->string('language_label', 32);
                $table->unsignedInteger('priority')->default(1);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['group_id', 'language_label'], 'sa_svg_items_group_lang_unique');
                $table->unique(['group_id', 'package_id'], 'sa_svg_items_group_pkg_unique');
                $table->index(['group_id', 'priority'], 'sa_svg_items_group_priority_idx');
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_campaign_action_tags')) {
            Schema::connection($this->connection)->table('sa_campaign_action_tags', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', 'variant_group_id')) {
                    $table->unsignedBigInteger('variant_group_id')->nullable()->after('release_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', 'variant_group_uuid')) {
                    $table->uuid('variant_group_uuid')->nullable()->after('variant_group_id')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', 'pinned_variants')) {
                    $table->json('pinned_variants')->nullable()->after('manifest_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_campaign_action_tags')) {
            Schema::connection($this->connection)->table('sa_campaign_action_tags', function (Blueprint $table): void {
                foreach (['pinned_variants', 'variant_group_uuid', 'variant_group_id'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_campaign_action_tags', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::connection($this->connection)->dropIfExists('sa_script_variant_group_items');
        Schema::connection($this->connection)->dropIfExists('sa_script_variant_groups');
    }
};
