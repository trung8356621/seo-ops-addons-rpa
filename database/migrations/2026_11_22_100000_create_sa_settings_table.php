<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global installation-level Social Automation settings (additive).
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('sa_settings')) {
            Schema::connection($this->connection)->create('sa_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('installation_id', 128)->index();
                $table->string('setting_key', 128);
                $table->json('value_json')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['installation_id', 'setting_key'], 'sa_settings_inst_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_settings');
    }
};
