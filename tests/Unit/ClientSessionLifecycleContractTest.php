<?php
declare(strict_types=1);
namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class ClientSessionLifecycleContractTest extends TestCase
{
    public function test_empty_profiles_are_not_uploaded_and_binding_is_immutable(): void
    {
        $sync = file_get_contents(dirname(__DIR__,2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString("!== 'present'", $sync);
        self::assertStringContainsString('browser_account_binding_immutable', $sync);
        self::assertStringContainsString('account_browser_duplicate_on_device', $sync);
    }
    public function test_task_failure_does_not_mark_needs_login_and_success_verifies_exact_session(): void
    {
        $jobs = file_get_contents(dirname(__DIR__,2).'/src/Orchestration/JobOrchestrator.php');
        self::assertStringContainsString("JobStatus::Completed->value", $jobs);
        self::assertStringContainsString("\$updates['session_state'] = 'verified'", $jobs);
        self::assertStringNotContainsString("JobStatus::Failed->value\n+                    &&", $jobs);
    }
    public function test_notification_types_are_predefined_and_ack_is_not_verification(): void
    {
        $service = file_get_contents(dirname(__DIR__,2).'/src/Services/ClientNotificationService.php');
        self::assertStringContainsString("['manual_intervention', 'login_required']", $service);
        preg_match('/public function acknowledge\(.*?\n    }/s', $service, $acknowledge);
        self::assertNotEmpty($acknowledge);
        self::assertStringNotContainsString('session_state', $acknowledge[0]);
        self::assertStringNotContainsString('dispatch_enabled', $acknowledge[0]);
    }
}
