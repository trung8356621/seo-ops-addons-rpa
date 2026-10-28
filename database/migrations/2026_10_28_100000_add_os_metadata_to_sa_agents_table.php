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
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('sa_agents')) {
            return;
        }

        $schema->table('sa_agents', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('sa_agents', 'os_family')) {
                $table->string('os_family', 16)->nullable()->after('display_name');
            }
            if (! $schema->hasColumn('sa_agents', 'os_label')) {
                $table->string('os_label', 64)->nullable()->after('os_family');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('sa_agents')) {
            return;
        }

        $schema->table('sa_agents', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('sa_agents', 'os_label')) {
                $table->dropColumn('os_label');
            }
            if ($schema->hasColumn('sa_agents', 'os_family')) {
                $table->dropColumn('os_family');
            }
        });
    }
};
