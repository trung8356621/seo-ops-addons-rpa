<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignTestScriptPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignRunMode;
use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use PHPUnit\Framework\TestCase;

final class CampaignCp19bTestModeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_run_mode_independent_of_execution_mode(): void
    {
        self::assertSame('test', CampaignRunMode::Test->value);
        self::assertSame('live', CampaignRunMode::Live->value);
        self::assertSame(CampaignRunMode::Live, CampaignRunMode::tryFromInput(null));
        self::assertTrue(CampaignRunMode::Test->requiresTestCompatibleScript());
        self::assertFalse(CampaignRunMode::Live->requiresTestCompatibleScript());
        self::assertSame('one_account_many_jobs', CampaignExecutionMode::OneAccountManyJobs->value);
    }

    public function test_test_mode_requires_explicit_compatible_script(): void
    {
        $p = new CampaignTestScriptPolicy;
        $reject = $p->assertCompatibleForTestMode([
            'steps' => [
                ['action' => 'CLICK_TARGET', 'name' => 'Open composer'],
                ['action' => 'CLICK_AND_TYPE', 'name' => 'Type'],
            ],
        ]);
        self::assertFalse($reject['ok']);
        self::assertContains('script_not_test_compatible', $reject['errors'] ?? []);
        self::assertNotEmpty($reject['limitations'] ?? []);
    }

    public function test_test_mode_rejects_publish_like_actions_even_when_marked(): void
    {
        $p = new CampaignTestScriptPolicy;
        $r = $p->assertCompatibleForTestMode([
            'test_mode_compatible' => true,
            'stops_before_publish' => true,
            'terminal_intent' => 'screenshot',
            'steps' => [
                ['action' => 'CLICK_AND_TYPE', 'name' => 'Type'],
                ['action' => 'submit', 'name' => 'Publish post'],
                ['action' => 'screenshot', 'name' => 'Capture'],
            ],
        ]);
        self::assertFalse($r['ok']);
        $hit = false;
        foreach ($r['errors'] ?? [] as $e) {
            if (str_starts_with((string) $e, 'script_test_forbidden_action:')) {
                $hit = true;
                break;
            }
        }
        self::assertTrue($hit);
    }

    public function test_compatible_test_script_with_screenshot_terminal(): void
    {
        $p = new CampaignTestScriptPolicy;
        $r = $p->assertCompatibleForTestMode([
            'campaign_test_compatible' => true,
            'stops_before_publish' => true,
            'terminal_intent' => 'screenshot',
            'steps' => [
                ['action' => 'CLICK_TARGET', 'name' => 'Open composer'],
                ['action' => 'CLICK_AND_TYPE', 'name' => 'Type mock'],
                ['action' => 'screenshot', 'name' => 'Evidence capture'],
            ],
        ]);
        self::assertTrue($r['ok']);
        self::assertSame('screenshot', $r['terminal_action']);
        self::assertTrue($r['stops_before_publish']);
    }

    public function test_untrusted_client_non_publish_flags_rejected(): void
    {
        $p = new CampaignTestScriptPolicy;
        $errors = $p->rejectUntrustedNonPublishClaims([
            'did_not_publish' => true,
            'metadata' => ['publish_skipped_verified' => true],
        ]);
        self::assertNotEmpty($errors);
        $hit = false;
        foreach ($errors as $e) {
            if (str_contains((string) $e, 'untrusted_non_publish_claim')) {
                $hit = true;
                break;
            }
        }
        self::assertTrue($hit);
    }

    public function test_screenshot_metadata_does_not_prove_publication(): void
    {
        $a = new ArtifactAcceptPolicy;
        $ok = $a->validateScreenshotMetadata('success', ['phase' => 'final', 'intent' => 'pre_publish_stop']);
        self::assertTrue($ok['ok']);
        self::assertSame('final', $ok['phase']);
        // Contract: success screenshot phase is capture evidence only.
        self::assertArrayNotHasKey('published', $ok);
    }

    public function test_test_mode_still_uses_global_account_lock(): void
    {
        $lock = new AccountLockPolicy;
        self::assertFalse($lock->canAssign(99, [
            ['job_id' => 1, 'account_id' => 99, 'status' => 'running'],
        ]));
        self::assertFalse($lock->canAssign(99, [
            ['job_id' => 2, 'account_id' => 99, 'status' => 'unknown'],
        ]));
    }

    public function test_migration_persists_run_mode(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        self::assertStringContainsString("run_mode", $sql);
        self::assertStringContainsString("default('live')", $sql);
        self::assertStringContainsString('test|live', $sql);
    }

    public function test_service_and_orchestrator_surface_run_mode(): void
    {
        $svc = (string) file_get_contents($this->root.'/src/Services/CampaignService.php');
        self::assertStringContainsString('CampaignRunMode', $svc);
        self::assertStringContainsString("'run_mode'", $svc);
        self::assertStringContainsString('assertCompatibleForTestMode', $svc);

        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString("'run_mode'", $orch);
        self::assertStringContainsString('normalizeArtifactRefs', $orch);
        self::assertStringContainsString('screenshot_proves_publication', $orch);
        self::assertStringContainsString('rejectUntrustedNonPublishClaims', $orch);
    }

    public function test_openapi_documents_run_mode_and_screenshot_artifacts(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('run_mode', $yaml);
        self::assertStringContainsString('enum: [test, live]', $yaml);
        self::assertStringContainsString('pre_publish_stop', $yaml);
        self::assertStringContainsString('did_not_publish', $yaml);
        self::assertStringContainsString('Threads Campaign Test', $yaml);
    }

    public function test_cp19a_failure_policy_unchanged(): void
    {
        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringNotContainsString('max_retries', $orch);
        self::assertStringNotContainsString('autoRetry', $orch);
        self::assertStringContainsString('WaitingResources', $orch);
        self::assertStringContainsString('FailedLocked', $orch);
    }
}
