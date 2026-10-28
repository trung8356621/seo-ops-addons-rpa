<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Client identity = sa_client_api_keys.id (1 key → 1 client agent).
 * Connection lease enforces single active connection per key (not a Device identity).
 * Legacy duplicate agents for the same key are isolated (client_api_key_id cleared).
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_agents')) {
            Schema::connection($conn)->table('sa_agents', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_agents', 'connection_token')) {
                    $table->string('connection_token', 64)->nullable()->after('client_api_key_id');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_agents', 'connection_lease_expires_at')) {
                    $table->timestamp('connection_lease_expires_at')->nullable()->index()->after('connection_token');
                }
            });

            // Isolate duplicate client agents sharing one API key; keep preferred row.
            $dupKeyIds = DB::connection($conn)->table('sa_agents')
                ->select('client_api_key_id')
                ->whereNotNull('client_api_key_id')
                ->where('agent_kind', 'client')
                ->groupBy('client_api_key_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('client_api_key_id');

            foreach ($dupKeyIds as $keyId) {
                $agents = DB::connection($conn)->table('sa_agents')
                    ->where('client_api_key_id', $keyId)
                    ->where('agent_kind', 'client')
                    ->orderByDesc('last_heartbeat_at')
                    ->orderByDesc('id')
                    ->get();

                $preferredId = null;
                foreach ($agents as $agent) {
                    $browserCount = (int) DB::connection($conn)->table('sa_browser_sessions')
                        ->where('agent_id', $agent->id)
                        ->count();
                    if ($browserCount > 0) {
                        $preferredId = (int) $agent->id;
                        break;
                    }
                }
                if ($preferredId === null && $agents->isNotEmpty()) {
                    $preferredId = (int) $agents->first()->id;
                }

                foreach ($agents as $agent) {
                    if ((int) $agent->id === $preferredId) {
                        continue;
                    }
                    DB::connection($conn)->table('sa_agents')->where('id', $agent->id)->update([
                        'client_api_key_id' => null,
                        'device_id' => 'legacy_orphan_'.(string) $agent->device_id,
                        'connection_token' => null,
                        'connection_lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
                }
            }

            // Canonical device_id for key-bound clients (DB column retained for runners / legacy FKs).
            $clients = DB::connection($conn)->table('sa_agents')
                ->whereNotNull('client_api_key_id')
                ->where('agent_kind', 'client')
                ->get(['id', 'client_api_key_id', 'installation_id']);

            foreach ($clients as $client) {
                $canonicalDeviceId = 'cak_'.(int) $client->client_api_key_id;
                $conflict = DB::connection($conn)->table('sa_agents')
                    ->where('installation_id', $client->installation_id)
                    ->where('device_id', $canonicalDeviceId)
                    ->where('id', '!=', $client->id)
                    ->exists();
                if ($conflict) {
                    // Leave non-canonical device_id; unique on client_api_key_id is the identity.
                    continue;
                }
                DB::connection($conn)->table('sa_agents')->where('id', $client->id)->update([
                    'device_id' => $canonicalDeviceId,
                    'updated_at' => now(),
                ]);
            }

            try {
                Schema::connection($conn)->table('sa_agents', function (Blueprint $table): void {
                    $table->unique('client_api_key_id', 'sa_agents_client_api_key_unique');
                });
            } catch (\Throwable) {
                // Index may already exist on re-run.
            }
        }
    }

    public function down(): void
    {
        $conn = $this->connection;
        if (! Schema::connection($conn)->hasTable('sa_agents')) {
            return;
        }

        Schema::connection($conn)->table('sa_agents', function (Blueprint $table): void {
            try {
                $table->dropUnique('sa_agents_client_api_key_unique');
            } catch (\Throwable) {
            }
            $drop = [];
            if (Schema::connection($this->connection)->hasColumn('sa_agents', 'connection_lease_expires_at')) {
                $drop[] = 'connection_lease_expires_at';
            }
            if (Schema::connection($this->connection)->hasColumn('sa_agents', 'connection_token')) {
                $drop[] = 'connection_token';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
