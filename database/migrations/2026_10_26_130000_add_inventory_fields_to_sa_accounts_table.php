<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Account Inventory for SEO-OPS Installation (omi_social_automation).
 * Platform accounts storage for RPA Client automation without plaintext passwords.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'installation_id')) {
                    $table->string('installation_id', 128)->nullable()->after('account_uuid')->index();
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'name')) {
                    $table->string('name', 255)->nullable()->after('platform');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'username')) {
                    $table->string('username', 255)->nullable()->after('name');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'email')) {
                    $table->string('email', 255)->nullable()->after('username');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'encrypted_password')) {
                    $table->text('encrypted_password')->nullable()->after('email');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'note')) {
                    $table->text('note')->nullable()->after('encrypted_password');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_accounts', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('note')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('sa_accounts')) {
            Schema::connection($this->connection)->table('sa_accounts', function (Blueprint $table): void {
                $columnsToDrop = [];
                foreach (['created_by', 'note', 'encrypted_password', 'email', 'username', 'name', 'installation_id'] as $col) {
                    if (Schema::connection($this->connection)->hasColumn('sa_accounts', $col)) {
                        $columnsToDrop[] = $col;
                    }
                }
                if ($columnsToDrop !== []) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
