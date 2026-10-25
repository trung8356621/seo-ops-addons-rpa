<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use PHPUnit\Framework\TestCase;

final class ActionTagAndManifestContractTest extends TestCase
{
    private PackagePublishValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PackagePublishValidator;
    }

    /** @return array<string, mixed> */
    private function baseScriptPayload(array $manifestOverrides = [], array $rootOverrides = []): array
    {
        return array_merge([
            'package_key' => 'facebook.group.post',
            'kind' => 'script',
            'name' => 'Facebook Group Post',
            'version' => '1',
            'schema_version' => 1,
            'manifest' => array_merge([
                'script_id' => 'facebook-group-post',
                'variables' => [],
                'steps' => [
                    ['id' => 's1', 'action' => 'CLICK_TARGET'],
                ],
                'video_demo' => [
                    'artifact_ref' => 'demo.webm',
                    'test_run_id' => 'run-1',
                    'reviewed' => true,
                    'audio' => false,
                    'desktop_capture' => false,
                ],
            ], $manifestOverrides),
            'dependencies' => [],
            'runtime_compatibility' => ['runner_min' => '2.0.0'],
        ], $rootOverrides);
    }

    public function test_legacy_script_without_result_type_and_tag_is_valid(): void
    {
        $payload = $this->baseScriptPayload();
        $result = $this->validator->validate($payload);

        self::assertTrue($result['ok'], 'Legacy script without result_type or action_tag_key must remain valid');
        self::assertArrayHasKey('content_digest', $result['data']);
    }

    public function test_status_result_type_valid_without_collection_type(): void
    {
        $payload = $this->baseScriptPayload([
            'action_tag_key' => 'facebook.group.post',
            'result_type' => 'status',
        ]);
        $result = $this->validator->validate($payload);

        self::assertTrue($result['ok']);
    }

    public function test_collection_result_type_valid_with_collection_type(): void
    {
        $payload = $this->baseScriptPayload([
            'action_tag_key' => 'facebook.group.collect_posts',
            'result_type' => 'collection',
            'collection_type' => 'facebook.group.posts',
        ]);
        $result = $this->validator->validate($payload);

        self::assertTrue($result['ok']);
    }

    public function test_collection_result_type_rejects_missing_collection_type(): void
    {
        $payload = $this->baseScriptPayload([
            'action_tag_key' => 'facebook.group.collect_posts',
            'result_type' => 'collection',
        ]);
        $result = $this->validator->validate($payload);

        self::assertFalse($result['ok']);
        self::assertContains('script_collection_type_required', $result['errors']);
    }

    public function test_invalid_result_type_is_rejected(): void
    {
        $payload = $this->baseScriptPayload([
            'result_type' => 'unknown_type',
        ]);
        $result = $this->validator->validate($payload);

        self::assertFalse($result['ok']);
        self::assertContains('script_result_type_invalid', $result['errors']);
    }

    public function test_invalid_action_tag_key_format_is_rejected(): void
    {
        $payload = $this->baseScriptPayload([
            'action_tag_key' => 'invalid tag with spaces!!',
        ]);
        $result = $this->validator->validate($payload);

        self::assertFalse($result['ok']);
        self::assertContains('script_action_tag_key_invalid', $result['errors']);
    }

    public function test_action_tag_checker_rejects_unregistered_tag(): void
    {
        $validatorWithTagCheck = new PackagePublishValidator(
            actionTagChecker: fn (string $key) => $key === 'facebook.group.post'
        );

        $validPayload = $this->baseScriptPayload(['action_tag_key' => 'facebook.group.post']);
        self::assertTrue($validatorWithTagCheck->validate($validPayload)['ok']);

        $invalidPayload = $this->baseScriptPayload(['action_tag_key' => 'facebook.unknown.action']);
        $result = $validatorWithTagCheck->validate($invalidPayload);
        self::assertFalse($result['ok']);
        self::assertContains('action_tag_not_found', $result['errors']);
    }

    public function test_manifest_fields_participate_in_content_digest(): void
    {
        $base = $this->validator->validate($this->baseScriptPayload());
        $withTag = $this->validator->validate($this->baseScriptPayload([
            'action_tag_key' => 'facebook.group.post',
            'result_type' => 'status',
        ]));
        $withCollection = $this->validator->validate($this->baseScriptPayload([
            'action_tag_key' => 'facebook.group.post',
            'result_type' => 'collection',
            'collection_type' => 'facebook.posts',
        ]));

        self::assertTrue($base['ok']);
        self::assertTrue($withTag['ok']);
        self::assertTrue($withCollection['ok']);

        // Different manifest content produces different digests
        self::assertNotSame($base['data']['content_digest'], $withTag['data']['content_digest']);
        self::assertNotSame($withTag['data']['content_digest'], $withCollection['data']['content_digest']);
    }

    public function test_action_tag_build_key(): void
    {
        self::assertSame('facebook.group.post', SaActionTag::buildKey('Facebook', 'Group', 'Post'));
        self::assertSame('facebook.group.comment', SaActionTag::buildKey('  Facebook ', ' group ', 'comment '));
    }

    public function test_script_request_statuses_and_types(): void
    {
        self::assertSame('create', SaScriptRequest::TYPE_CREATE);
        self::assertSame('fix', SaScriptRequest::TYPE_FIX);
        self::assertSame('pending', SaScriptRequest::STATUS_PENDING);
        self::assertSame('developing', SaScriptRequest::STATUS_DEVELOPING);
        self::assertSame('published', SaScriptRequest::STATUS_PUBLISHED);
        self::assertSame('needs_fix', SaScriptRequest::STATUS_NEEDS_FIX);
    }
}
