<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RecoveryRegistryV1ContractTest extends TestCase
{
    private string $root;
    protected function setUp(): void { parent::setUp(); $this->root=dirname(__DIR__,2); }

    public function test_schema_keeps_error_tags_templates_and_source_artifacts_separate(): void
    {
        $m=$this->read('database/migrations/2026_11_27_100000_create_sa_recovery_registry_tables.php');
        self::assertStringContainsString("create('sa_recovery_error_tags'",$m);
        self::assertStringContainsString("create('sa_recovery_visual_templates'",$m);
        self::assertStringContainsString("foreignId('error_tag_id')",$m);
        self::assertStringContainsString("unsignedBigInteger('source_artifact_id')",$m);
        self::assertStringNotContainsString("binary('image",strtolower($m));
    }

    public function test_binding_requires_published_compatible_tenant_owned_script_release(): void
    {
        $s=$this->read('src/Services/RecoveryRegistryService.php');
        self::assertStringContainsString("where('status','published')",$s);
        self::assertStringContainsString('$package->kind!==\'script\'',$s);
        self::assertStringContainsString('created_by_user_id',$s);
        self::assertStringContainsString('recovery_handler_v1',$s);
        self::assertStringContainsString('published_compatible_owned_handler_required',$s);
    }

    public function test_runtime_registry_filters_disabled_templates_and_non_executable_tags(): void
    {
        $s=$this->read('src/Services/RecoveryRegistryService.php');
        self::assertStringContainsString("templates'=>fn(\$x)=>\$x->where('is_enabled',true)",$s);
        self::assertStringContainsString("where('is_enabled',true)->whereNotNull('handler_release_id')",$s);
        self::assertStringContainsString('$tag->templates->isEmpty()',$s);
    }

    public function test_recovery_reporting_preserves_attempt_and_lease_continuity(): void
    {
        $s=$this->read('src/Services/RecoveryRegistryService.php');
        self::assertStringContainsString("where('attempt_uuid'",$s);
        self::assertStringContainsString('hash_equals((string)$attempt->lease_token',$s);
        self::assertStringContainsString('recovery_attempt_limit_exceeded',$s);
        self::assertStringContainsString('recursive_handler_execution_forbidden',$s);
        self::assertStringNotContainsString('SaJobAttempt::query()->create',$s);
    }

    public function test_authorization_is_owner_and_installation_scoped(): void
    {
        $s=$this->read('src/Services/RecoveryRegistryService.php');
        self::assertStringContainsString("whereIn('owner_user_id',\$permittedOwners)",$s);
        self::assertStringContainsString("where('installation_id',\$installationId)",$s);
        self::assertStringContainsString('attempt_not_authorized',$s);
    }

    public function test_routes_and_openapi_expose_web_admin_and_client_contracts(): void
    {
        $provider=$this->read('src/SocialAutomationServiceProvider.php');
        $api=$this->read('openapi/openapi.yaml');
        foreach(['recovery-registry','recovery-results','recovery-handler-requests'] as $path){self::assertStringContainsString($path,$provider);self::assertStringContainsString($path,$api);}
        self::assertStringContainsString('matched_visual_template_id',$api);
        self::assertStringContainsString('click_coordinates',$api);
    }

    public function test_runtime_asset_access_is_owner_installation_and_exact_binding_scoped(): void
    {
        $service=$this->read('src/Services/RecoveryRuntimeAssetService.php');
        foreach(["whereIn('owner_user_id', \$permittedOwners)","where('installation_id', \$installationId)","where('is_enabled', true)",'handler_release_not_bound','handler_release_unavailable','recovery_handler_v1'] as $gate)self::assertStringContainsString($gate,$service);
        self::assertStringContainsString('ScriptReleaseAssetReader',$service);
        self::assertStringContainsString("where('kind', 'screenshot')",$service);
        self::assertStringContainsString("hash('sha256', \$bytes)",$service);
        self::assertStringNotContainsString("'storage_path' =>",$service);
    }

    public function test_runtime_routes_require_connection_lease_and_preserve_job_pinned_routes(): void
    {
        $provider=$this->read('src/SocialAutomationServiceProvider.php');
        foreach(['/recovery/templates/image','/recovery/handlers/pull','/recovery/handlers/asset','/scripts/pull','/scripts/asset'] as $path)self::assertStringContainsString($path,$provider);
        self::assertStringContainsString('EnsureClientConnectionLease::class',$provider);
        $api=$this->read('openapi/openapi.yaml');
        self::assertStringContainsString('Fractional ROI relative to the current viewport',$api);
        self::assertStringContainsString('click_coordinates: { x: 796, y: 590 }',$api);
        self::assertStringContainsString('modal_bounding_box: { x: 0.2222',$api);
    }

    public function test_demo_free_release_creation_and_retry_reach_published(): void
    {
        $service = $this->read('src/PackageRegistry/PackageRegistryService.php');
        self::assertStringContainsString("\$pendingMedia ? 'pending_media' : 'published'", $service);
        self::assertStringContainsString("\$publishWithoutMedia && \$existing->status === 'pending_media'", $service);
        self::assertStringContainsString("created_by_user_id !== \$userId", $service);
        self::assertStringContainsString("existing->execution_digest", $service);
        self::assertStringContainsString("\$existing->fill(['status' => 'published', 'published_at' => now()])->save()", $service);
    }

    private function read(string $path): string { return (string)file_get_contents($this->root.'/'.$path); }
}
