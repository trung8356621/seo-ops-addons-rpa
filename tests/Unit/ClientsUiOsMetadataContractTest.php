<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for Clients UI OS metadata + Account Name display (no Browser Name).
 */
final class ClientsUiOsMetadataContractTest extends TestCase
{
    public function test_agent_registry_persists_os_on_register_and_heartbeat(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/AgentRegistryService.php');
        self::assertStringContainsString('DeviceOsMetadata::applyToFill', $src);
        self::assertStringContainsString("'os_family'", $src);
        self::assertStringContainsString("'os_label'", $src);
    }

    public function test_client_dashboard_exposes_os_and_account_fallback(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString("'os_label'", $src);
        self::assertStringContainsString('Chưa liên kết Account', $src);
        self::assertStringNotContainsString('Legacy / Unlinked', $src);
    }

    public function test_clients_blade_is_vietnamese_and_hides_browser_name_title(): void
    {
        $sessions = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/pages/sessions.blade.php');
        $device = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/pages/partials/device-group.blade.php');

        self::assertStringContainsString('Máy trực tuyến', $sessions);
        self::assertStringContainsString('Máy ngoại tuyến / Dữ liệu cũ', $sessions);
        self::assertStringContainsString('Phiên sẵn sàng nhận Task', $sessions);
        self::assertStringNotContainsString('Online Devices', $sessions);
        self::assertStringNotContainsString('Machines online', $sessions);

        self::assertStringContainsString('account_name', $device);
        self::assertStringContainsString('Có tín hiệu', $device);
        self::assertStringContainsString('Không có tín hiệu', $device);
        self::assertStringContainsString('Thông tin kỹ thuật', $device);
        self::assertStringContainsString('os_label', $device);
        self::assertStringContainsString('Chưa có thông tin hệ điều hành', $device);
        self::assertStringNotContainsString('browser[\'name\']', $device);
        self::assertStringNotContainsString('$browser[\'name\']', $device);
    }
}
