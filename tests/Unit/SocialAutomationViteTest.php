<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationVite;
use PHPUnit\Framework\TestCase;

final class SocialAutomationViteTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_vite_paths_and_constants(): void
    {
        $vite = new SocialAutomationVite();

        self::assertSame('build-social-automation', $vite->buildDirectory());
        self::assertStringContainsString('hot-social-automation', $vite->hotFilePath());
        self::assertStringContainsString('build-social-automation'.DIRECTORY_SEPARATOR.'manifest.json', $vite->manifestPath());
    }

    public function test_dev_server_status_detects_missing_hot_file(): void
    {
        $vite = new SocialAutomationVite();
        // When hot file does not exist, it must be false
        if (! is_file($vite->hotFilePath())) {
            self::assertFalse($vite->isDevServerRunning());
        }
    }

    public function test_production_tags_render_from_manifest(): void
    {
        $vite = new SocialAutomationVite();
        $tags = (string) $vite->tags();

        self::assertStringContainsString('<script type="module"', $tags);
        self::assertStringContainsString('build-social-automation/assets/index-', $tags);
        self::assertStringContainsString('<link rel="stylesheet"', $tags);
        self::assertStringContainsString('build-social-automation/assets/action-tags-', $tags);
        self::assertStringNotContainsString('bundle asset chưa sẵn sàng', $tags);
    }

    public function test_blade_uses_wire_ignore_and_vite_helper(): void
    {
        $bladePath = $this->addonRoot().'/resources/views/filament/pages/action-tags.blade.php';
        self::assertFileExists($bladePath);

        $content = (string) file_get_contents($bladePath);
        self::assertStringContainsString('wire:ignore', $content);
        self::assertStringContainsString('wire:key="action-tags-workspace-shell"', $content);
        self::assertStringContainsString('id="action-registry-root"', $content);
        self::assertStringContainsString('SocialAutomationVite', $content);
        self::assertStringContainsString('app(SocialAutomationVite::class)->tags()', $content);

        // Verify old broken inline mechanism is removed
        self::assertStringNotContainsString('__DIR__', $content);
        self::assertStringNotContainsString('file_get_contents', $content);

        // Verify @use directive is at file level, not illegal inside @php
        self::assertStringContainsString("@use('Omnichannel\\Addons\\SocialAutomation\\Support\\SocialAutomationVite')", $content);
        self::assertMatchesRegularExpression('/@php\s+((?!use\s).)*?@endphp/s', $content);
    }

    public function test_react_entrypoint_lifecycle_supports_livewire_spa(): void
    {
        $indexTsx = $this->addonRoot().'/resources/js/action-tags/index.tsx';
        self::assertFileExists($indexTsx);

        $content = (string) file_get_contents($indexTsx);
        self::assertStringContainsString('livewire:navigated', $content);
        self::assertStringContainsString('DOMContentLoaded', $content);
        self::assertStringContainsString('unmount()', $content);
        self::assertStringContainsString('activeRoot', $content);
        self::assertStringContainsString('__sa_registry_listener_attached', $content);
    }

    public function test_scoped_css_exists_and_compiled(): void
    {
        $cssPath = $this->addonRoot().'/resources/css/action-tags.css';
        self::assertFileExists($cssPath);

        $content = (string) file_get_contents($cssPath);
        self::assertStringContainsString('.action-tags-workspace', $content);
        self::assertStringContainsString('.badge-ready', $content);
        self::assertStringContainsString('.badge-pending', $content);
    }
}
