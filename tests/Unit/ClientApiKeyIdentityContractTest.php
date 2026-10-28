<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for Client API Key identity (no Electron Device UUID).
 */
final class ClientApiKeyIdentityContractTest extends TestCase
{
    public function test_register_does_not_require_client_device_id(): void
    {
        $registry = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/AgentRegistryService.php');
        self::assertStringContainsString('registerClientByApiKey', $registry);
        self::assertStringContainsString('cak_', $registry);
        self::assertStringContainsString('ClientConnectionLease::ERROR_KEY_IN_USE', $registry);
        self::assertSame('CLIENT_KEY_IN_USE', \Omnichannel\Addons\SocialAutomation\Domain\ClientConnectionLease::ERROR_KEY_IN_USE);
        self::assertStringContainsString('lockForUpdate', $registry);
    }

    public function test_controller_strips_device_id_and_returns_409(): void
    {
        $ctrl = (string) file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/RpaClientApiController.php');
        self::assertStringContainsString("unset(\$payload['device_id'])", $ctrl);
        self::assertStringContainsString('findClientByApiKeyId', $ctrl);
        self::assertStringContainsString('authorizedClient', $ctrl);
        self::assertStringContainsString('409', $ctrl);
        self::assertStringNotContainsString('authorizedDevice', $ctrl);
        self::assertStringNotContainsString('device_id_required', $ctrl);
    }

    public function test_browser_sync_requires_account_id_not_browser_name(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString('account_id_required', $sync);
        self::assertStringNotContainsString("'Browser '.substr", $sync);
    }

    public function test_schedule_tick_runs_client_cleanup(): void
    {
        $cmd = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/SocialAutomationScheduleTickCommand.php');
        self::assertStringContainsString('ClientCleanupService', $cmd);
        self::assertStringContainsString('cleanupStaleClients', $cmd);

        $cleanup = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/ClientCleanupService.php');
        self::assertStringContainsString('STALE_HOURS = 72', $cleanup);
        self::assertStringContainsString('browserSessions()->count()', $cleanup);
    }

    public function test_migration_adds_lease_and_unique_key(): void
    {
        $mig = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_10_28_120000_client_api_key_identity_and_connection_lease.php'
        );
        self::assertStringContainsString('connection_token', $mig);
        self::assertStringContainsString('connection_lease_expires_at', $mig);
        self::assertStringContainsString('sa_agents_client_api_key_unique', $mig);
        self::assertStringContainsString('legacy_orphan_', $mig);
    }
}
