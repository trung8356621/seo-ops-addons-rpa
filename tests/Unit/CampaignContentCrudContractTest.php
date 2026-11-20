<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use PHPUnit\Framework\TestCase;

final class CampaignContentCrudContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_service_exposes_content_crud_methods(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Services/CampaignService.php');
        self::assertStringContainsString('function listContents', $src);
        self::assertStringContainsString('function createContent', $src);
        self::assertStringContainsString('function updateContent', $src);
        self::assertStringContainsString('function contentDto', $src);
        self::assertStringContainsString('external_ref_duplicate', $src);
        self::assertStringContainsString('content_not_editable', $src);
        self::assertStringContainsString('account_pin_mismatch', $src);
        // CRUD must not call dispatchCampaign / claimNext (nudge next_recheck_at only).
        $createFn = $this->extractMethod($src, 'createContent');
        $updateFn = $this->extractMethod($src, 'updateContent');
        self::assertStringNotContainsString('dispatchCampaign', $createFn);
        self::assertStringNotContainsString('dispatchCampaign', $updateFn);
        self::assertStringNotContainsString('claimNext', $createFn);
        self::assertStringNotContainsString('claimNext', $updateFn);
        self::assertStringContainsString('next_recheck_at = now()', $createFn);
    }

    private function extractMethod(string $src, string $name): string
    {
        $pos = strpos($src, 'function '.$name);
        self::assertNotFalse($pos, $name.' missing');
        $slice = substr($src, (int) $pos, 4500);

        return $slice;
    }

    public function test_controller_wires_index_store_update(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Http/Controllers/StaffCampaignContentController.php');
        self::assertStringContainsString('function index', $src);
        self::assertStringContainsString('function store', $src);
        self::assertStringContainsString('function update', $src);
        self::assertStringContainsString('createContent', $src);
        self::assertStringContainsString('listContents', $src);
        self::assertStringContainsString('updateContent', $src);
        // Preserve existing admin actions.
        self::assertStringContainsString('function retry', $src);
        self::assertStringContainsString('function skip', $src);
        self::assertStringContainsString('adminSkipOrCancel', $src);
    }

    public function test_openapi_documents_content_crud(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('/social-automation/api/campaigns/{id}/contents:', $yaml);
        self::assertStringContainsString('/social-automation/api/campaigns/{id}/contents/{contentId}:', $yaml);
        self::assertStringContainsString('staffCampaignContentsList', $yaml);
        self::assertStringContainsString('staffCampaignContentsCreate', $yaml);
        self::assertStringContainsString('staffCampaignContentsUpdate', $yaml);
        self::assertStringContainsString('CampaignContentCreateRequest', $yaml);
        self::assertStringContainsString('CampaignContentUpdateRequest', $yaml);
        self::assertStringContainsString('CampaignContentItem', $yaml);
        self::assertStringContainsString('Manual Content create after Campaign create', $yaml);
        self::assertStringContainsString('Does **not** create Jobs', $yaml);
        // Existing admin actions remain.
        self::assertStringContainsString('staffCampaignContentsRetry', $yaml);
        self::assertStringContainsString('staffCampaignContentsSkip', $yaml);
        self::assertStringContainsString('staffCampaignContentsCancel', $yaml);
    }

    public function test_editable_statuses_are_dispatchable_only(): void
    {
        self::assertTrue(CampaignContentStatus::Pending->isDispatchable());
        self::assertTrue(CampaignContentStatus::WaitingResources->isDispatchable());
        self::assertFalse(CampaignContentStatus::Running->isDispatchable());
        self::assertFalse(CampaignContentStatus::Success->isDispatchable());
        self::assertFalse(CampaignContentStatus::FailedLocked->isDispatchable());
        self::assertFalse(CampaignContentStatus::Unknown->isDispatchable());
        self::assertFalse(CampaignContentStatus::Skipped->isDispatchable());
        self::assertFalse(CampaignContentStatus::Cancelled->isDispatchable());
    }

    public function test_terminal_campaigns_reject_create(): void
    {
        self::assertTrue(CampaignStatus::Cancelled->isTerminal());
        self::assertTrue(CampaignStatus::Completed->isTerminal());
        self::assertFalse(CampaignStatus::Draft->isTerminal());
        self::assertFalse(CampaignStatus::Running->isTerminal());
        self::assertFalse(CampaignStatus::Paused->isTerminal());
    }
}
