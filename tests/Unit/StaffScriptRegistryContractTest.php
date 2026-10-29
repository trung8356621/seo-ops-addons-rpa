<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptRegistryController;
use PHPUnit\Framework\TestCase;

/**
 * CP12A — Staff Script Registry REST contracts.
 */
final class StaffScriptRegistryContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_provider_registers_staff_session_get_routes_only(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');

        self::assertStringContainsString("prefix('api/script-registry')", $provider);
        self::assertStringContainsString('StaffScriptRegistryController::class', $provider);
        self::assertStringContainsString("'social-automation.script-registry.index'", $provider);
        self::assertStringContainsString("'social-automation.script-registry.show'", $provider);

        // Nested under the same Staff Session $middleware group as action-registry.
        $registryPos = strpos($provider, "prefix('api/script-registry')");
        self::assertNotFalse($registryPos);
        $middlewarePos = strrpos(substr($provider, 0, $registryPos), 'Route::middleware($middleware)');
        self::assertNotFalse($middlewarePos);

        // GET only — no mutation verbs in this block.
        $block = substr($provider, (int) $registryPos, 450);
        self::assertStringContainsString('Route::get', $block);
        self::assertStringNotContainsString('Route::post', $block);
        self::assertStringNotContainsString('Route::put', $block);
        self::assertStringNotContainsString('Route::delete', $block);
        self::assertStringNotContainsString('EnsureRpaAdminUserApi', $block);
        self::assertStringNotContainsString('EnsureRpaClientApiKey', $block);
    }

    public function test_controller_reuses_ui_service_and_access_gate(): void
    {
        $src = (string) file_get_contents(
            $this->root.'/src/Http/Controllers/ScriptRegistry/StaffScriptRegistryController.php'
        );

        self::assertStringContainsString('ScriptRegistryUiService', $src);
        self::assertStringContainsString('listScriptsForUi', $src);
        self::assertStringContainsString('scriptDetail', $src);
        self::assertStringContainsString('assertCanAccess', $src);
        self::assertStringContainsString('SocialAutomationAccess', $src);
        self::assertStringNotContainsString('EnsureRpaAdminUserApi', $src);
        self::assertStringNotContainsString('RpaClientApiKey', $src);
        self::assertStringNotContainsString('storage_path', $src);
        self::assertStringNotContainsString('Storage::', $src);
    }

    public function test_ui_service_exposes_identity_and_auth_flags(): void
    {
        $ui = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptRegistryUiService.php');
        self::assertStringContainsString("'is_authentication'", $ui);
        self::assertStringContainsString("'script_id'", $ui);
        self::assertStringContainsString("'package_key'", $ui);
        self::assertStringContainsString('video_demo_status', $ui);
        self::assertStringContainsString('assets_complete', $ui);
        // script_id is derived from manifest, with package_key fallback — not forced equal.
        self::assertStringContainsString("manifest['script_id'] ?? \$package->package_key", $ui);
    }

    public function test_list_mapper_maps_service_fields_without_secrets(): void
    {
        $mapped = StaffScriptRegistryController::mapListItem([
            'package_key' => 'pkg.auth.login',
            'script_id' => 'script-auth-login-v2',
            'name' => 'Auth Login',
            'description' => 'Staff desc',
            'is_authentication' => true,
            'action_tag_key' => 'facebook.group.post',
            'version' => '1.2.0',
            'release_id' => 9,
            'status' => 'published',
            'assets_complete' => true,
            'video_demo_status' => 'ready',
            'has_playable_video' => true,
            'has_thumbnail' => true,
            'video_media_id' => 42,
            'thumbnail_media_id' => 43,
            'published_at' => '2026-10-29T01:00:00+00:00',
            'updated_by_label' => 'Staff',
            'widget_dependency_count' => 2,
            'storage_path' => '/var/secret/video.webm',
            'content_digest' => str_repeat('a', 64),
        ]);

        self::assertSame('pkg.auth.login', $mapped['package_key']);
        self::assertSame('script-auth-login-v2', $mapped['script_id']);
        self::assertNotSame($mapped['package_key'], $mapped['script_id']);
        self::assertTrue($mapped['is_authentication']);
        self::assertSame(42, $mapped['video_media_id']);
        self::assertSame(43, $mapped['thumbnail_media_id']);
        self::assertSame('/social-automation/script-media/42', $mapped['video_media_url']);
        self::assertSame('/social-automation/script-media/43', $mapped['thumbnail_media_url']);
        self::assertArrayNotHasKey('storage_path', $mapped);
        self::assertArrayNotHasKey('content_digest', $mapped);
        self::assertStringNotContainsString('/var/', json_encode($mapped, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret', json_encode($mapped, JSON_THROW_ON_ERROR));
    }

    public function test_list_mapper_empty_media_ids_yield_null_urls(): void
    {
        $mapped = StaffScriptRegistryController::mapListItem([
            'package_key' => 'pkg.x',
            'script_id' => 'pkg.x',
            'name' => 'X',
            'version' => '1.0.0',
            'release_id' => 1,
            'status' => 'published',
            'is_authentication' => false,
            'assets_complete' => false,
            'video_demo_status' => 'missing',
            'video_media_id' => null,
            'thumbnail_media_id' => null,
        ]);

        self::assertNull($mapped['video_media_id']);
        self::assertNull($mapped['thumbnail_media_id']);
        self::assertNull($mapped['video_media_url']);
        self::assertNull($mapped['thumbnail_media_url']);
    }

    public function test_detail_mapper_sanitizes_release_assets_and_caps_releases(): void
    {
        $releases = [];
        for ($i = 1; $i <= 30; $i++) {
            $releases[] = [
                'id' => $i,
                'version' => '1.0.'.$i,
                'status' => 'published',
                'content_digest' => str_repeat('b', 64),
                'execution_digest' => null,
                'published_at' => null,
                'sync_source' => 'rpa',
                'variables' => [['key' => 'a']],
                'steps' => [['id' => '1']],
                'assets' => [[
                    'path' => '/internal/storage/asset-'.$i.'.bin',
                    'sha256' => str_repeat('c', 64),
                ]],
                'video_demo' => ['path' => '/internal/videos/demo.webm', 'artifact_ref' => 'demo.webm'],
                'video_demo_status' => 'ready',
                'video_media_id' => 100 + $i,
                'thumbnail_media_id' => 200 + $i,
                'runtime_requirements' => [],
            ];
        }

        $detail = StaffScriptRegistryController::mapDetail([
            'package_key' => 'pkg.y',
            'script_id' => 'script-y',
            'name' => 'Y',
            'description' => 'd',
            'is_authentication' => false,
            'action_tag_key' => null,
            'sync_source' => 'rpa',
            'updated_by_label' => '—',
            'widget_dependencies' => [],
            'releases' => $releases,
        ]);

        self::assertCount(StaffScriptRegistryController::MAX_RELEASES, $detail['releases']);
        $encoded = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('/internal/', $encoded);
        self::assertStringNotContainsString('storage', strtolower($encoded));
        self::assertArrayNotHasKey('assets', $detail['releases'][0]);
        self::assertArrayNotHasKey('video_demo', $detail['releases'][0]);
        self::assertArrayHasKey('asset_count', $detail['releases'][0]);
        self::assertTrue($detail['releases'][0]['assets_complete']);
        self::assertTrue($detail['releases'][0]['has_video_demo_meta']);
        self::assertSame('pkg.y', $detail['package_key']);
        self::assertSame('script-y', $detail['script_id']);
    }

    public function test_detail_missing_returns_null_contract_in_controller_source(): void
    {
        $src = (string) file_get_contents(
            $this->root.'/src/Http/Controllers/ScriptRegistry/StaffScriptRegistryController.php'
        );
        self::assertStringContainsString("['ok' => false, 'error' => 'not_found']", $src);
        self::assertStringContainsString(', 404)', $src);
    }

    public function test_openapi_documents_staff_script_registry(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/social-automation/api/script-registry:', $yaml);
        self::assertStringContainsString('/social-automation/api/script-registry/{id}:', $yaml);
        self::assertStringContainsString('staffScriptRegistryIndex', $yaml);
        self::assertStringContainsString('staffScriptRegistryShow', $yaml);
        self::assertStringContainsString('StaffScriptRegistryListResponse', $yaml);
        self::assertStringContainsString('package_key', $yaml);
        self::assertStringContainsString('is_authentication', $yaml);
        self::assertStringContainsString('video_media_url', $yaml);
        self::assertStringNotContainsString('storage_path', $yaml);
    }

    public function test_rpa_admin_script_routes_unchanged(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString('scripts/sync/plan', $provider);
        self::assertStringContainsString('scripts/publish', $provider);
        self::assertStringContainsString('scripts/pull', $provider);
        self::assertStringContainsString('scripts/video-demo', $provider);
        self::assertStringContainsString('EnsureRpaAdminUserApi::class', $provider);
    }

    public function test_script_media_authorization_unchanged(): void
    {
        $media = (string) file_get_contents(
            $this->root.'/src/Http/Controllers/ScriptReleaseMediaController.php'
        );
        self::assertStringContainsString('assertCanAccess', $media);
        self::assertStringContainsString('findReadyMedia', $media);
        self::assertStringContainsString("where('kind', 'script')", $media);
        self::assertStringContainsString('Cache-Control', $media);
        self::assertStringContainsString('private, max-age=300', $media);
    }
}
