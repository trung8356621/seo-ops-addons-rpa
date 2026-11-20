<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CampaignShortcodeV2ContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_adds_shortcode_columns(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_20_100000_add_campaign_shortcode_v2_columns.php');
        self::assertStringContainsString('shortcode_mapping', $sql);
        self::assertStringContainsString('shortcode_mapping_version', $sql);
        self::assertStringContainsString('source_format', $sql);
        self::assertStringContainsString('raw_source', $sql);
        self::assertStringContainsString('mapping_version', $sql);
        self::assertStringContainsString('omi_social_automation', $sql);
        self::assertStringNotContainsString('dropIfExists(\'sa_campaigns\')', $sql);
    }

    public function test_service_allows_empty_contents_and_start_gate(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Services/CampaignService.php');
        self::assertStringNotContainsString("errors' => ['contents_required']", $src);
        self::assertStringContainsString('Zero contents allowed', $src);
        self::assertStringContainsString('assertActionableContents', $src);
        self::assertStringContainsString('no_actionable_content', $src);
        self::assertStringContainsString('function mockPreview', $src);
        self::assertStringContainsString('function mockSave', $src);
        self::assertStringContainsString('lockForUpdate', $src);
        self::assertStringContainsString('mock_batch_results', $src);
        self::assertStringContainsString('mapping_immutable_after_start', $src);

        $mockSave = $this->extractMethod($src, 'mockSave');
        self::assertStringNotContainsString('dispatchCampaign', $mockSave);
        $mockPreview = $this->extractMethodUntil($src, 'mockPreview', 'function mockSave');
        self::assertStringNotContainsString('SaCampaignContent::query()->create', $mockPreview);
        self::assertStringNotContainsString('dispatchCampaign', $mockPreview);
    }

    public function test_routes_and_controller_mock_preview_save(): void
    {
        $provider = (string) file_get_contents($this->root.'/src/SocialAutomationServiceProvider.php');
        self::assertStringContainsString("contents/mock-preview", $provider);
        self::assertStringContainsString("contents/mock-save", $provider);
        $ctrl = (string) file_get_contents($this->root.'/src/Http/Controllers/StaffCampaignContentController.php');
        self::assertStringContainsString('function mockPreview', $ctrl);
        self::assertStringContainsString('function mockSave', $ctrl);
        self::assertStringContainsString('campaignShortcodeReady', $ctrl);
    }

    public function test_orchestrator_revalidates_before_dispatch(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertStringContainsString('assertContentInputForDispatch', $src);
        self::assertStringContainsString('mapping_version_stale', $src);
        self::assertStringContainsString('content_input_empty', $src);
        // Hard pin unchanged
        self::assertStringContainsString('pinned_account_unavailable', $src);
        self::assertStringContainsString('no silent fallback', $src);
    }

    public function test_openapi_shortcode_v2_contract(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('CampaignShortcodeMapping', $yaml);
        self::assertStringContainsString('empty_campaign_shortcode_v2', $yaml);
        self::assertStringContainsString('staffCampaignContentsMockPreview', $yaml);
        self::assertStringContainsString('staffCampaignContentsMockSave', $yaml);
        self::assertStringContainsString('no_actionable_content', $yaml);
        self::assertStringContainsString('atomic batch', $yaml);
        self::assertStringContainsString('raw_source', $yaml);
        self::assertStringContainsString('richtext_format_unsupported', $yaml);
        // contents no longer required on create
        self::assertStringContainsString('required: [name, platform, execution_mode, script_id]', $yaml);
        self::assertStringContainsString('minItems: 0', $yaml);
        // legacy example preserved
        self::assertStringContainsString('wordpress_draft_poc', $yaml);
        self::assertStringContainsString('poc-draft-001', $yaml);
    }

    public function test_domain_classes_exist(): void
    {
        foreach ([
            'CampaignShortcodeParser.php',
            'CampaignShortcodeRichText.php',
            'CampaignShortcodeMappingValidator.php',
            'CampaignShortcodeTypedValidator.php',
            'CampaignShortcodePipeline.php',
            'CampaignShortcodeMockGenerator.php',
            'CampaignScriptVariableSchema.php',
        ] as $file) {
            self::assertFileExists($this->root.'/src/Domain/'.$file);
        }
        $schema = (string) file_get_contents($this->root.'/src/Support/SocialAutomationSchema.php');
        self::assertStringContainsString('function campaignShortcodeReady', $schema);
    }

    public function test_single_pipeline_used_by_service(): void
    {
        $src = (string) file_get_contents($this->root.'/src/Services/CampaignService.php');
        self::assertStringContainsString('CampaignShortcodePipeline', $src);
        self::assertStringContainsString('shortcodePipeline->process', $src);
        self::assertStringContainsString('shortcodePipeline->validateStoredInput', $src);
        self::assertStringContainsString('mockGenerator->generate', $src);
    }

    private function extractMethod(string $src, string $name): string
    {
        $pos = strpos($src, 'function '.$name);
        self::assertNotFalse($pos, $name.' missing');

        return substr($src, (int) $pos, 6000);
    }

    private function extractMethodUntil(string $src, string $name, string $untilNeedle): string
    {
        $pos = strpos($src, 'function '.$name);
        self::assertNotFalse($pos, $name.' missing');
        $slice = substr($src, (int) $pos);
        $end = strpos($slice, $untilNeedle);
        if ($end !== false) {
            $slice = substr($slice, 0, $end);
        }

        return $slice;
    }
}
