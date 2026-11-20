<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use PHPUnit\Framework\TestCase;

/**
 * WordPress Draft Campaign POC contracts (1 Campaign → 1 Account → 1 Job → Save Draft).
 * Source/OpenAPI presence only — no MOCK E2E claims.
 */
final class CampaignWpDraftPocContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_create_pins_account_and_published_script_only(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Services/CampaignService.php');
        self::assertStringContainsString('resolvePinnedAccount', $src);
        self::assertStringContainsString('pinned_account_id', $src);
        self::assertStringContainsString("where('status', 'published')", $src);
        self::assertStringContainsString('SaActionTagScript', $src);
        self::assertStringContainsString('action_tag_key', $src);
        self::assertStringContainsString('assigned_account_id', $src);
        self::assertStringContainsString('scriptPinDto', $src);
    }

    public function test_openapi_documents_account_pin_and_action_tag_script_id(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('account_id:', $yaml);
        self::assertStringContainsString('Hard Account UUID pin', $yaml);
        self::assertStringContainsString('NEVER silently falls back', $yaml);
        self::assertStringContainsString('pinned_account_unavailable', $yaml);
        self::assertStringContainsString('mayhopphat.create_post', $yaml);
        self::assertStringContainsString('one_account_one_job', $yaml);
        self::assertStringContainsString('wordpress_draft_poc', $yaml);
        self::assertStringContainsString('status=published', $yaml);
        self::assertStringContainsString('script_content_digest', $yaml);
    }

    public function test_dispatch_hard_pins_account_with_no_silent_fallback(): void
    {
        $orc = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString('resolveRequiredAccountId', $orc);
        self::assertStringContainsString('requiredAccountId', $orc);
        self::assertStringContainsString('pinned_account_unavailable', $orc);
        self::assertStringContainsString('pinned_account_mismatch', $orc);
        self::assertStringContainsString('no silent fallback', $orc);
        self::assertStringContainsString('no fallback', $orc);
        // Claim includes exact release pin + distinct digests
        self::assertStringContainsString("'release_id'", $orc);
        self::assertStringContainsString("'content_digest'", $orc);
        self::assertStringContainsString("'execution_digest'", $orc);
        self::assertStringContainsString('script_pin', $orc);
    }

    public function test_reserve_uses_hashed_campaign_idempotency_key(): void
    {
        $orc = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString('JobIdempotencyKey::forCampaignContent', $orc);
        self::assertStringNotContainsString("'campaign:'.\$campaign->campaign_uuid.':content:'", $orc);
        self::assertStringContainsString('social_automation.campaign_dispatch_failed', $orc);
        // Logging must not dump QueryException message (SQL + payload bindings).
        self::assertStringNotContainsString("'message' => \$e->getMessage()", $orc);
        self::assertStringContainsString("'sqlstate'", $orc);
        // Attempt fencing: new reserve still keys off prior job count + 1.
        self::assertStringContainsString('where(\'campaign_content_id\', $content->id)->count() + 1', $orc);
        self::assertStringContainsString("'fencing_generation' => \$fencing", $orc);
    }

    public function test_poc_execution_mode_and_live_run_mode(): void
    {
        self::assertSame('one_account_one_job', CampaignExecutionMode::OneAccountOneJob->value);
        self::assertSame('live', CampaignRunMode::Live->value);
        self::assertFalse(CampaignRunMode::Live->requiresTestCompatibleScript());
        self::assertTrue(CampaignRunMode::Test->requiresTestCompatibleScript());
    }

    public function test_save_draft_script_without_test_markers_is_rejected_in_test_mode(): void
    {
        $p = new CampaignTestScriptPolicy;
        // Mirrors mayhopphat.create_post published shape: title type + Save Draft click, no test markers.
        $r = $p->assertCompatibleForTestMode([
            'action_tag_key' => 'mayhopphat.create_post',
            'variables' => [['key' => 'title', 'type' => 'text']],
            'steps' => [
                ['action' => 'CLICK_AND_TYPE', 'name' => 'Ttitle'],
                ['action' => 'CLICK_TARGET', 'name' => 'Lưu nháp'],
            ],
        ]);
        self::assertFalse($r['ok']);
        self::assertContains('script_not_test_compatible', $r['errors'] ?? []);
    }

    public function test_create_post_action_name_is_forbidden_only_as_step_action_not_package_key(): void
    {
        self::assertContains('create_post', CampaignTestScriptPolicy::KNOWN_PUBLISH_ACTIONS);
        $p = new CampaignTestScriptPolicy;
        $okShape = $p->assertCompatibleForTestMode([
            'campaign_test_compatible' => true,
            'stops_before_publish' => true,
            'terminal_intent' => 'screenshot',
            'steps' => [
                ['action' => 'CLICK_AND_TYPE', 'name' => 'Title'],
                ['action' => 'CLICK_TARGET', 'name' => 'Save draft'],
                ['action' => 'screenshot', 'name' => 'Evidence'],
            ],
        ]);
        self::assertTrue($okShape['ok']);
    }

    public function test_staff_content_routes_include_crud_and_admin_actions(): void
    {
        $src = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("prefix('api/campaign-contents')", $src);
        self::assertStringContainsString('retry', $src);
        self::assertStringContainsString('skip', $src);
        // Campaign create still accepts contents[]; post-create CRUD is nested under campaigns/{id}/contents.
        self::assertStringContainsString("Route::post('/', [StaffCampaignController::class, 'store'])", $src);
        self::assertStringContainsString("Route::post('/{id}/contents', [StaffCampaignContentController::class, 'store'])", $src);
        self::assertStringContainsString("Route::get('/{id}/contents', [StaffCampaignContentController::class, 'index'])", $src);
        self::assertStringContainsString("Route::put('/{id}/contents/{contentId}', [StaffCampaignContentController::class, 'update'])", $src);
    }

    public function test_client_job_claim_ack_result_routes_present(): void
    {
        $src = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("/jobs/claim", $src);
        self::assertStringContainsString("/jobs/{id}/ack", $src);
        self::assertStringContainsString("/jobs/{id}/progress", $src);
        self::assertStringContainsString("/jobs/{id}/result", $src);
        self::assertStringContainsString('ClientCampaignJobController', $src);
    }
}
