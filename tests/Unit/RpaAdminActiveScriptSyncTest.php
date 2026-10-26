<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin\RpaAdminScriptSyncController;
use Omnichannel\Addons\SocialAutomation\SocialAutomationServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RpaAdminActiveScriptSyncTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_active_scripts_and_asset_methods_exist_on_controller(): void
    {
        $ref = new ReflectionClass(RpaAdminScriptSyncController::class);
        self::assertTrue($ref->hasMethod('activeScripts'));
        self::assertTrue($ref->hasMethod('getAsset'));
        self::assertTrue($ref->hasMethod('getVideoDemo'));
    }

    public function test_active_scripts_routes_registered_in_provider(): void
    {
        $providerSrc = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("Route::get('/active-scripts'", $providerSrc);
        self::assertStringContainsString("Route::get('/scripts/asset'", $providerSrc);
        self::assertStringContainsString("Route::get('/scripts/video-demo'", $providerSrc);
    }

    public function test_active_scripts_response_shape_contract(): void
    {
        $controllerSrc = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaAdmin/RpaAdminScriptSyncController.php');
        self::assertStringContainsString("'active_scripts'", $controllerSrc);
        self::assertStringContainsString("'action_tag_key'", $controllerSrc);
        self::assertStringContainsString("'script_id'", $controllerSrc);
        self::assertStringContainsString("'content_digest'", $controllerSrc);
        self::assertStringContainsString("'release'", $controllerSrc);
        self::assertStringContainsString("'video_demo'", $controllerSrc);
    }

    public function test_video_demo_endpoint_contract(): void
    {
        $controllerSrc = (string) file_get_contents($this->root.'/src/Http/Controllers/RpaAdmin/RpaAdminScriptSyncController.php');
        self::assertStringContainsString('getVideoDemo', $controllerSrc);
        self::assertStringContainsString('package_key_and_version_required', $controllerSrc);
        self::assertStringContainsString('media_not_found', $controllerSrc);
        self::assertStringContainsString('media_file_missing', $controllerSrc);
        self::assertStringContainsString('content_base64', $controllerSrc);
        self::assertStringContainsString('binary', $controllerSrc);
    }
}
