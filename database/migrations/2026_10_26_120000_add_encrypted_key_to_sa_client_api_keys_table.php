<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Add encrypted_key to sa_client_api_keys for authorized recoverable viewing.
 * Plaintext is NEVER stored in database — only encrypted payload and one-way hash.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_client_api_keys')
            && ! Schema::connection($this->connection)->hasColumn('sa_client_api_keys', 'encrypted_key')
        ) {
            Schema::connection($this->connection)->table('sa_client_api_keys', function (Blueprint $table): void {
                $table->text('encrypted_key')->nullable()->after('key_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_client_api_keys')
            && Schema::connection($this->connection)->hasColumn('sa_client_api_keys', 'encrypted_key')
        ) {
            Schema::connection($this->connection)->table('sa_client_api_keys', function (Blueprint $table): void {
                $table->dropColumn('encrypted_key');
            });
        }
    }
};
