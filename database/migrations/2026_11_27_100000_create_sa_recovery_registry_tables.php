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
        Schema::connection($this->connection)->create('sa_recovery_error_tags', function (Blueprint $table): void {
            $table->id();
            $table->uuid('error_tag_uuid')->unique();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('installation_id', 128)->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('platform', 64);
            $table->string('site_scope', 255)->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedBigInteger('handler_package_id')->nullable()->index();
            $table->unsignedBigInteger('handler_release_id')->nullable()->index();
            $table->json('recovery_policy');
            $table->unsignedTinyInteger('max_attempts_per_step')->default(1);
            $table->timestamps();
            $table->index(['owner_user_id', 'platform', 'is_enabled'], 'sa_recovery_tags_owner_platform_enabled');
        });

        Schema::connection($this->connection)->create('sa_recovery_visual_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('template_uuid')->unique();
            $table->foreignId('error_tag_id')->constrained('sa_recovery_error_tags')->cascadeOnDelete();
            $table->unsignedBigInteger('source_attempt_id');
            $table->unsignedBigInteger('source_artifact_id');
            $table->json('modal_roi');
            $table->json('ocr_anchors')->nullable();
            $table->json('target_roi')->nullable();
            $table->json('recognition_config');
            $table->decimal('match_threshold', 5, 4)->default(0.8500);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->unique(['error_tag_id', 'template_uuid']);
            $table->index(['error_tag_id', 'is_enabled']);
            $table->index(['source_attempt_id', 'source_artifact_id'], 'sa_recovery_tpl_source');
        });

        Schema::connection($this->connection)->create('sa_recovery_handler_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('error_tag_id')->constrained('sa_recovery_error_tags')->cascadeOnDelete();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('claimed_by_user_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->index(['error_tag_id', 'status']);
        });

        Schema::connection($this->connection)->create('sa_recovery_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('recovery_uuid')->unique();
            $table->foreignId('attempt_id')->constrained('sa_job_attempts')->cascadeOnDelete();
            $table->foreignId('error_tag_id')->constrained('sa_recovery_error_tags')->restrictOnDelete();
            $table->foreignId('visual_template_id')->constrained('sa_recovery_visual_templates')->restrictOnDelete();
            $table->unsignedBigInteger('handler_release_id');
            $table->string('failed_step_id', 128);
            $table->unsignedTinyInteger('recovery_attempt_no');
            $table->decimal('match_confidence', 5, 4);
            $table->unsignedBigInteger('source_artifact_id')->nullable();
            $table->json('runtime_input');
            $table->timestamp('handler_started_at')->nullable();
            $table->timestamp('handler_finished_at')->nullable();
            $table->string('verification_outcome', 32);
            $table->string('final_job_outcome', 32)->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'failed_step_id', 'recovery_attempt_no'], 'sa_recovery_result_attempt_step_no');
            $table->index(['attempt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_recovery_results');
        Schema::connection($this->connection)->dropIfExists('sa_recovery_handler_requests');
        Schema::connection($this->connection)->dropIfExists('sa_recovery_visual_templates');
        Schema::connection($this->connection)->dropIfExists('sa_recovery_error_tags');
    }
};
