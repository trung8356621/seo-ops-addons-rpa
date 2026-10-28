<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\DeviceOsMetadata;
use PHPUnit\Framework\TestCase;

final class DeviceOsMetadataTest extends TestCase
{
    public function test_parses_explicit_os_family_and_label(): void
    {
        $parsed = DeviceOsMetadata::fromPayload([
            'os_family' => 'windows',
            'os_label' => 'Windows 11',
        ]);

        $this->assertSame('windows', $parsed['os_family']);
        $this->assertSame('Windows 11', $parsed['os_label']);
    }

    public function test_infers_family_from_legacy_platform_without_guessing_version(): void
    {
        $parsed = DeviceOsMetadata::fromPayload([
            'platform' => 'linux',
            'os_version' => 'Linux 6.8.0 (x64)',
        ]);

        $this->assertSame('linux', $parsed['os_family']);
        $this->assertSame('Linux', $parsed['os_label']);
    }

    public function test_apply_to_fill_preserves_existing_when_payload_omits_os(): void
    {
        $fill = DeviceOsMetadata::applyToFill(
            ['display_name' => 'Desk'],
            ['app_version' => '0.1.0'],
            'windows',
            'Windows 10',
        );

        $this->assertSame('windows', $fill['os_family']);
        $this->assertSame('Windows 10', $fill['os_label']);
    }

    public function test_rejects_unknown_family(): void
    {
        $parsed = DeviceOsMetadata::fromPayload([
            'os_family' => 'solaris',
            'os_label' => 'Solaris 11',
        ]);

        $this->assertNull($parsed['os_family']);
        $this->assertSame('Solaris 11', $parsed['os_label']);
    }
}
