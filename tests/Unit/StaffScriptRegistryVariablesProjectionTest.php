<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ScriptRegistry\StaffScriptRegistryController;
use PHPUnit\Framework\TestCase;

final class StaffScriptRegistryVariablesProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_release_v1_with_title_projects_title(): void
    {
        $mapped = StaffScriptRegistryController::mapRelease([
            'id' => 10,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('a', 64),
            'variables' => [
                ['id' => 'v1', 'key' => 'title', 'type' => 'text', 'required' => true],
            ],
            'steps' => [['id' => 's1']],
            'assets' => [],
            'video_demo_status' => 'missing',
        ]);

        self::assertSame(10, $mapped['id']);
        self::assertSame('1', $mapped['version']);
        self::assertSame('published', $mapped['status']);
        self::assertSame('ok', $mapped['variables_status']);
        self::assertSame([], $mapped['variables_errors']);
        self::assertSame(1, $mapped['variable_count']);
        self::assertCount(1, $mapped['variables']);
        self::assertSame('title', $mapped['variables'][0]['key']);
        self::assertSame('title', $mapped['variables'][0]['name']);
        self::assertSame('string', $mapped['variables'][0]['type']);
        self::assertTrue($mapped['variables'][0]['required']);
        self::assertTrue($mapped['variables'][0]['shortcode_compatible']);
    }

    public function test_multiple_releases_keep_correct_version_and_variables(): void
    {
        $detail = StaffScriptRegistryController::mapDetail([
            'package_key' => 'pkg.create_post',
            'script_id' => 'mayhopphat.create_post',
            'name' => 'Create Post',
            'description' => null,
            'is_authentication' => false,
            'action_tag_key' => 'mayhopphat.create_post',
            'sync_source' => 'rpa',
            'updated_by_label' => '—',
            'widget_dependencies' => [],
            'releases' => [
                [
                    'id' => 2,
                    'version' => '2',
                    'status' => 'published',
                    'content_digest' => str_repeat('b', 64),
                    'variables' => [
                        ['id' => '1', 'key' => 'title', 'type' => 'text', 'required' => true],
                        ['id' => '2', 'key' => 'url', 'type' => 'url', 'required' => false],
                    ],
                    'steps' => [],
                    'assets' => [],
                    'video_demo_status' => 'missing',
                ],
                [
                    'id' => 1,
                    'version' => '1',
                    'status' => 'published',
                    'content_digest' => str_repeat('a', 64),
                    'variables' => [
                        ['id' => '1', 'key' => 'title', 'type' => 'text', 'required' => true],
                    ],
                    'steps' => [],
                    'assets' => [],
                    'video_demo_status' => 'missing',
                ],
            ],
        ]);

        self::assertSame(2, $detail['release_id']);
        self::assertSame('2', $detail['version']);
        self::assertSame('title', $detail['releases'][0]['variables'][0]['key']);
        self::assertSame('url', $detail['releases'][0]['variables'][1]['key']);
        self::assertSame('1', $detail['releases'][1]['version']);
        self::assertCount(1, $detail['releases'][1]['variables']);
        self::assertSame('title', $detail['releases'][1]['variables'][0]['key']);
    }

    public function test_empty_variables_status_empty(): void
    {
        $mapped = StaffScriptRegistryController::mapRelease([
            'id' => 3,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('c', 64),
            'variables' => [],
            'steps' => [],
            'assets' => [],
            'video_demo_status' => 'missing',
        ]);
        self::assertSame('empty', $mapped['variables_status']);
        self::assertSame([], $mapped['variables']);
        self::assertSame(0, $mapped['variable_count']);
        self::assertSame([], $mapped['variables_errors']);
    }

    public function test_malformed_and_missing_variables_are_invalid(): void
    {
        $missing = StaffScriptRegistryController::mapRelease([
            'id' => 4,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('d', 64),
            'steps' => [],
            'assets' => [],
            'video_demo_status' => 'missing',
        ]);
        self::assertSame('invalid', $missing['variables_status']);
        self::assertContains('script_variables_missing', $missing['variables_errors']);
        self::assertSame([], $missing['variables']);

        $malformed = StaffScriptRegistryController::mapRelease([
            'id' => 5,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('e', 64),
            'variables' => 'not-a-list',
            'steps' => [],
            'assets' => [],
            'video_demo_status' => 'missing',
        ]);
        self::assertSame('invalid', $malformed['variables_status']);
        self::assertNotEmpty($malformed['variables_errors']);
        self::assertSame([], $malformed['variables']);
    }

    public function test_sensitive_metadata_not_exposed(): void
    {
        $mapped = StaffScriptRegistryController::mapRelease([
            'id' => 6,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('f', 64),
            'variables' => [
                [
                    'id' => '1',
                    'key' => 'title',
                    'type' => 'text',
                    'required' => true,
                    'default' => 'password=super-secret',
                    'password' => 'plain',
                ],
            ],
            'steps' => [['code' => 'evil', 'password' => 'x']],
            'assets' => [['path' => '/internal/storage/secret.bin', 'sha256' => str_repeat('1', 64)]],
            'video_demo' => ['path' => '/internal/videos/demo.webm'],
            'video_demo_status' => 'ready',
            'video_media_id' => 9,
        ]);

        $json = json_encode($mapped, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('super-secret', $json);
        self::assertStringNotContainsString('password', $json);
        self::assertStringNotContainsString('/internal/', $json);
        self::assertArrayNotHasKey('assets', $mapped);
        self::assertArrayNotHasKey('steps', $mapped);
        self::assertArrayNotHasKey('video_demo', $mapped);
        self::assertArrayNotHasKey('default', $mapped['variables'][0]);
        self::assertSame('title', $mapped['variables'][0]['key']);
    }

    public function test_projection_agrees_with_campaign_mapping_validator(): void
    {
        $manifest = [
            'variables' => [
                ['id' => '1', 'key' => 'title', 'type' => 'text', 'required' => true],
                ['id' => '2', 'key' => 'link', 'type' => 'url', 'required' => false],
            ],
        ];
        $schema = new CampaignScriptVariableSchema;
        $parsed = $schema->fromManifest($manifest);
        self::assertTrue($parsed['ok']);
        $projected = $schema->projectStaffList($manifest);
        self::assertSame('ok', $projected['status']);
        $keys = array_column($projected['variables'], 'key');
        self::assertSame(array_keys($parsed['variables']), $keys);

        $map = (new CampaignShortcodeMappingValidator)->validate([
            'entries' => [
                ['shortcode' => 'body', 'variable' => 'title'],
                ['shortcode' => 'link', 'variable' => 'link'],
            ],
        ], $parsed['variables']);
        self::assertTrue($map['ok']);
    }

    public function test_does_not_hardcode_wordpress_title_or_action_tag(): void
    {
        $mapped = StaffScriptRegistryController::mapRelease([
            'id' => 7,
            'version' => '1',
            'status' => 'published',
            'content_digest' => str_repeat('0', 64),
            'variables' => [
                ['id' => '1', 'key' => 'caption', 'type' => 'string', 'required' => true],
            ],
            'steps' => [],
            'assets' => [],
            'video_demo_status' => 'missing',
            'action_tag_key' => 'mayhopphat.create_post',
        ]);
        self::assertSame(['caption'], array_column($mapped['variables'], 'key'));
        self::assertNotContains('title', array_column($mapped['variables'], 'key'));
        self::assertArrayNotHasKey('action_tag_key', $mapped);
    }

    public function test_openapi_documents_release_variables(): void
    {
        $yaml = (string) file_get_contents($this->root.'/openapi/openapi.yaml');
        self::assertStringContainsString('StaffScriptReleaseVariable', $yaml);
        self::assertStringContainsString('StaffScriptRegistryRelease', $yaml);
        self::assertStringContainsString('variables_status', $yaml);
        self::assertStringContainsString('shortcode_compatible', $yaml);
    }

    public function test_existing_detail_mapper_compatibility_still_holds(): void
    {
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
            'releases' => [[
                'id' => 1,
                'version' => '1.0.0',
                'status' => 'published',
                'content_digest' => str_repeat('b', 64),
                'variables' => [['id' => '1', 'key' => 'a', 'type' => 'text']],
                'steps' => [['id' => '1']],
                'assets' => [[
                    'path' => '/internal/storage/asset.bin',
                    'sha256' => str_repeat('c', 64),
                ]],
                'video_demo' => ['path' => '/internal/videos/demo.webm'],
                'video_demo_status' => 'ready',
                'video_media_id' => 101,
                'thumbnail_media_id' => 201,
                'runtime_requirements' => [],
            ]],
        ]);
        self::assertArrayHasKey('variables', $detail['releases'][0]);
        self::assertArrayNotHasKey('assets', $detail['releases'][0]);
        self::assertStringNotContainsString('/internal/', json_encode($detail, JSON_THROW_ON_ERROR));
    }
}
