<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action Tag Registry + Script Requests on omi_social_automation.
 * No cross-database foreign keys.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_action_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('tag_key', 128);
            $table->string('platform', 64);
            $table->string('target', 64);
            $table->string('action', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique('tag_key');
            $table->index(['platform', 'target', 'action']);
        });

        Schema::connection($this->connection)->create('sa_script_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64);
            $table->unsignedBigInteger('action_tag_id');
            $table->string('action_tag_key', 128);
            $table->string('request_type', 32); // create | fix
            $table->string('status', 32)->default('pending'); // pending | developing | published | needs_fix
            $table->string('script_id', 128)->nullable();
            $table->string('script_version', 64)->nullable();
            $table->text('error_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique('request_id');
            $table->index('action_tag_key');
            $table->index('status');
            $table->index('script_id');
        });

        Schema::connection($this->connection)->create('sa_action_tag_scripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('action_tag_id');
            $table->string('action_tag_key', 128);
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('release_id')->nullable();
            $table->string('script_id', 128);
            $table->string('script_version', 64)->nullable();
            $table->timestamps();

            $table->unique(['action_tag_id', 'script_id', 'script_version'], 'sa_tag_scripts_unique');
            $table->index('action_tag_key');
            $table->index('script_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_action_tag_scripts');
        Schema::connection($this->connection)->dropIfExists('sa_script_requests');
        Schema::connection($this->connection)->dropIfExists('sa_action_tags');
    }
};
