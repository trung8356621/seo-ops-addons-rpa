<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\AccountLockPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\AttemptFencePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignInputSanitizer;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignModePolicy;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignRecheckPolicy;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignAttemptStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignExecutionMode;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use PHPUnit\Framework\TestCase;

/**
 * CP19A campaign contracts — pure policies + source/route/migration presence.
 * SQLite lock semantics covered in CampaignConcurrencyDbTest when PDO SQLite available.
 */
final class CampaignCp19aContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_additive_campaign_tables(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        self::assertStringContainsString('sa_campaigns', $sql);
        self::assertStringContainsString('sa_campaign_contents', $sql);
        self::assertStringContainsString('sa_campaign_account_assignments', $sql);
        self::assertStringContainsString('next_recheck_at', $sql);
        self::assertStringContainsString('lease_token', $sql);
        self::assertStringContainsString('fencing_generation', $sql);
        self::assertStringContainsString('omi_social_automation', $sql);
        self::assertStringNotContainsString('dropIfExists(\'sa_jobs\')', $sql);
        self::assertStringNotContainsString('dropIfExists(\'sa_accounts\')', $sql);
    }

    public function test_staff_and_client_routes_registered(): void
    {
        $src = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("prefix('api/campaigns')", $src);
        self::assertStringContainsString('StaffCampaignController', $src);
        self::assertStringContainsString('campaign-contents', $src);
        self::assertStringContainsString("/jobs/claim", $src);
        self::assertStringContainsString('ClientCampaignJobController', $src);
        self::assertStringContainsString('CampaignOrchestrator', $src);
    }

    public function test_execution_modes_and_content_states(): void
    {
        self::assertSame('one_account_one_job', CampaignExecutionMode::OneAccountOneJob->value);
        self::assertSame('one_account_many_jobs', CampaignExecutionMode::OneAccountManyJobs->value);
        self::assertTrue(CampaignContentStatus::WaitingResources->isDispatchable());
        self::assertTrue(CampaignContentStatus::FailedLocked->isTerminal());
        self::assertFalse(CampaignContentStatus::Unknown->isTerminal());
        self::assertFalse(CampaignStatus::Paused->allowsDispatch());
        self::assertTrue(CampaignStatus::Running->allowsDispatch());
    }

    public function test_mode_a_account_consumed_even_after_failure_semantics(): void
    {
        $p = new CampaignModePolicy;
        self::assertFalse($p->canAssignAccount(
            CampaignExecutionMode::OneAccountOneJob,
            10,
            [10],
        ));
        self::assertTrue($p->canAssignAccount(
            CampaignExecutionMode::OneAccountOneJob,
            11,
            [10],
        ));
        self::assertTrue($p->assignmentIsDurable(CampaignExecutionMode::OneAccountOneJob));
    }

    public function test_mode_b_sequential_same_account(): void
    {
        $p = new CampaignModePolicy;
        self::assertFalse($p->canAssignAccount(
            CampaignExecutionMode::OneAccountManyJobs,
            10,
            [],
            [10],
        ));
        self::assertTrue($p->canAssignAccount(
            CampaignExecutionMode::OneAccountManyJobs,
            10,
            [],
            [],
        ));
    }

    public function test_global_account_lock_blocks_cross_campaign(): void
    {
        $p = new AccountLockPolicy;
        self::assertFalse($p->canAssign(42, [
            ['job_id' => 1, 'account_id' => 42, 'status' => 'running'],
        ]));
        self::assertFalse($p->canAssign(42, [
            ['job_id' => 2, 'account_id' => 42, 'status' => 'unknown'],
        ]));
    }

    public function test_input_sanitizer_rejects_plaintext_password(): void
    {
        $s = new CampaignInputSanitizer;
        $bad = $s->sanitize(['username' => 'u', 'password' => 'secret123']);
        self::assertFalse($bad['ok']);
        $ok = $s->sanitize([
            'username' => '{account.username}',
            'password' => '{account.password}',
        ]);
        self::assertTrue($ok['ok']);
        self::assertSame('{account.password}', $ok['input']['password']);
    }

    public function test_input_sanitizer_rejects_unsupported_script_variable(): void
    {
        $s = new CampaignInputSanitizer;
        $r = $s->sanitize(['foo' => 'bar'], ['username']);
        self::assertFalse($r['ok']);
        self::assertNotEmpty($r['errors'] ?? []);
    }

    public function test_recheck_policy_durable_interval(): void
    {
        $p = new CampaignRecheckPolicy;
        self::assertSame(3600, $p->normalizeInterval(3600));
        self::assertSame(3600, $p->normalizeInterval(0));
        $from = new \DateTimeImmutable('2026-11-02T00:00:00Z');
        $next = $p->nextRecheckAt($from, 3600);
        self::assertSame('2026-11-02T01:00:00+00:00', $next->format('c'));
        self::assertTrue($p->isDue(CampaignStatus::Running, $from, new \DateTimeImmutable('2026-11-02T01:00:00Z'), true));
        self::assertFalse($p->isDue(CampaignStatus::Paused, null, new \DateTimeImmutable('now'), true));
        self::assertFalse($p->isDue(CampaignStatus::Running, null, new \DateTimeImmutable('now'), false));
    }

    public function test_no_auto_retry_attempt_status_mapping(): void
    {
        self::assertSame('failed', CampaignAttemptStatus::Failed->value);
        self::assertTrue(CampaignAttemptStatus::Failed->isTerminal());
        self::assertFalse(CampaignAttemptStatus::Unknown->isTerminal());
        self::assertSame('failed_locked', CampaignContentStatus::FailedLocked->value);
    }

    public function test_stale_lease_and_late_result_fence(): void
    {
        $f = new AttemptFencePolicy;
        self::assertFalse($f->validate('old', null)['ok']);
        self::assertFalse($f->validate('old', 'new')['ok']);
        self::assertTrue($f->isDuplicateTerminal('a1', 'a1', 'completed'));
        self::assertFalse($f->isDuplicateTerminal('a1', 'a2', 'completed'));
    }

    public function test_schedule_tick_includes_campaign_recheck(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Console/SocialAutomationScheduleTickCommand.php');
        self::assertStringContainsString('recheckDueCampaigns', $src);
        self::assertStringContainsString('reconcileExpiredCampaignLeases', $src);
    }

    public function test_staff_permission_gate_owner_admin_only(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Support/SocialAutomationAccess.php');
        self::assertStringContainsString('canManageCampaigns', $src);
        self::assertStringContainsString('assertCanManageCampaigns', $src);
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/StaffCampaignController.php');
        self::assertStringContainsString('assertCanManageCampaigns', $ctrl);
        self::assertStringNotContainsString('assertCanMutate', $ctrl);
    }

    public function test_openapi_documents_campaign_and_client_job_routes(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/social-automation/api/campaigns', $yaml);
        self::assertStringContainsString('/api/v1/social-automation/client/jobs/claim', $yaml);
        self::assertStringContainsString('campaign-contents', $yaml);
        self::assertStringContainsString('waiting_resources', $yaml);
        self::assertStringContainsString('one_account_many_jobs', $yaml);
        self::assertStringContainsString('lease_token', $yaml);
    }

    public function test_failed_content_never_auto_retries_in_orchestrator(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString('FailedLocked', $src);
        self::assertStringContainsString('adminRetry', $src);
        self::assertStringNotContainsString('autoRetry', $src);
        self::assertStringNotContainsString('max_retries', $src);
        // Resource shortage → waiting_resources, not failed
        self::assertStringContainsString('WaitingResources', $src);
    }
}
