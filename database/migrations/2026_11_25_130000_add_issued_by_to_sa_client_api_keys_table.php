<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Separate minting actor (issued_by) from designated Client owner (created_by).
 * created_by remains the Staff/Owner ownership slot used by enrollment.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('sa_client_api_keys')) {
            return;
        }
        if ($schema->hasColumn('sa_client_api_keys', 'issued_by')) {
            return;
        }

        $schema->table('sa_client_api_keys', function (Blueprint $table): void {
            $table->unsignedBigInteger('issued_by')->nullable()->after('created_by')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('sa_client_api_keys')) {
            return;
        }
        if (! $schema->hasColumn('sa_client_api_keys', 'issued_by')) {
            return;
        }

        $schema->table('sa_client_api_keys', function (Blueprint $table): void {
            $table->dropColumn('issued_by');
        });
    }
};
