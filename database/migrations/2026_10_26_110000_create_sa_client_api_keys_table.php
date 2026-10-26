<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Phase 1: Client API Key Foundation (omi_social_automation).
 * Store hashed API keys for RPA Client machines scoped to SEO-OPS Installation.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_client_api_keys')) {
            Schema::connection($this->connection)->create('sa_client_api_keys', function (Blueprint $table): void {
                $table->id();
                $table->string('installation_id', 128)->index();
                $table->string('name', 255);
                $table->string('key_prefix', 32)->index();
                $table->string('key_hash', 64)->unique();
                $table->unsignedBigInteger('created_by')->nullable()->index(); // Core users.id (audit only)
                $table->timestamp('last_used_at')->nullable()->index();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->timestamps();

                $table->index(['installation_id', 'revoked_at']);
                $table->index(['key_prefix', 'revoked_at']);
            });
        }

        if (Schema::connection($this->connection)->hasTable('sa_agents')
            && ! Schema::connection($this->connection)->hasColumn('sa_agents', 'client_api_key_id')
        ) {
            Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
                $table->unsignedBigInteger('client_api_key_id')->nullable()->after('credential_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_agents')
            && Schema::connection($this->connection)->hasColumn('sa_agents', 'client_api_key_id')
        ) {
            Schema::connection($this->connection)->table('sa_agents', function (Blueprint $table): void {
                $table->dropColumn('client_api_key_id');
            });
        }

        Schema::connection($this->connection)->dropIfExists('sa_client_api_keys');
    }
};
