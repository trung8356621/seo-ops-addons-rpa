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
        self::assertStringContainsString("where('owner_user_id',\$ownerUserId)",$s);
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

    private function read(string $path): string { return (string)file_get_contents($this->root.'/'.$path); }
}
