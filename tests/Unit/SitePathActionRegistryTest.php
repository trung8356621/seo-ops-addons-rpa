<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Models\SaAction;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaScriptRequest;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use PHPUnit\Framework\TestCase;

final class SitePathActionRegistryTest extends TestCase
{
    public function test_site_base_url_normalization(): void
    {
        self::assertSame('https://www.facebook.com', SaSite::normalizeBaseUrl('www.facebook.com'));
        self::assertSame('https://www.facebook.com', SaSite::normalizeBaseUrl('https://www.facebook.com/'));
        self::assertSame('http://twitter.com', SaSite::normalizeBaseUrl('http://twitter.com/'));
        self::assertSame('', SaSite::normalizeBaseUrl('   '));
    }

    public function test_path_pattern_parse_single_placeholder(): void
    {
        $inputs = PathPatternResolver::parseInputs('/groups/{group_id}');

        self::assertCount(1, $inputs);
        self::assertSame('group_id', $inputs[0]['key']);
        self::assertSame('string', $inputs[0]['type']);
        self::assertTrue($inputs[0]['required']);
    }

    public function test_path_pattern_parse_multiple_placeholders(): void
    {
        $inputs = PathPatternResolver::parseInputs('/groups/{group_id}/posts/{post_id}');

        self::assertCount(2, $inputs);
        self::assertSame('group_id', $inputs[0]['key']);
        self::assertSame('post_id', $inputs[1]['key']);
    }

    public function test_path_pattern_no_placeholders(): void
    {
        $inputs = PathPatternResolver::parseInputs('/');
        self::assertSame([], $inputs);

        $inputs2 = PathPatternResolver::parseInputs('/explore/trending');
        self::assertSame([], $inputs2);
    }

    public function test_url_resolve_success(): void
    {
        $res = PathPatternResolver::resolveUrl(
            'https://www.facebook.com',
            '/groups/{group_id}',
            ['group_id' => '123456789']
        );

        self::assertTrue($res['valid']);
        self::assertSame([], $res['missing']);
        self::assertSame('https://www.facebook.com/groups/123456789', $res['url']);
    }

    public function test_url_resolve_multiple_segments(): void
    {
        $res = PathPatternResolver::resolveUrl(
            'https://www.facebook.com',
            '/groups/{group_id}/posts/{post_id}',
            ['group_id' => '123', 'post_id' => '456']
        );

        self::assertTrue($res['valid']);
        self::assertSame([], $res['missing']);
        self::assertSame('https://www.facebook.com/groups/123/posts/456', $res['url']);
    }

    public function test_url_resolve_fails_when_required_input_missing(): void
    {
        $res = PathPatternResolver::resolveUrl(
            'https://www.facebook.com',
            '/groups/{group_id}',
            []
        );

        self::assertFalse($res['valid']);
        self::assertContains('group_id', $res['missing']);
    }

    public function test_url_resolve_encodes_special_characters(): void
    {
        $res = PathPatternResolver::resolveUrl(
            'https://twitter.com',
            '/{username}',
            ['username' => 'john doe&co']
        );

        self::assertTrue($res['valid']);
        self::assertSame('https://twitter.com/john%20doe%26co', $res['url']);
    }

    public function test_action_tag_stable_key_generation(): void
    {
        $key = SaActionTag::buildKey('Facebook', 'Group', 'Post');
        self::assertSame('facebook.group.post', $key);

        $key2 = SaActionTag::buildKey('  Facebook ', ' group_post_detail ', ' comment ');
        self::assertSame('facebook.group_post_detail.comment', $key2);
    }

    public function test_v1_actions_allowlist(): void
    {
        self::assertSame('post', SaAction::ACTION_POST);
        self::assertSame('comment', SaAction::ACTION_COMMENT);
        self::assertSame('collect', SaAction::ACTION_COLLECT);

        // Disallowed V1 actions
        $forbidden = ['like', 'share'];
        foreach ($forbidden as $act) {
            self::assertNotSame(SaAction::ACTION_POST, $act);
            self::assertNotSame(SaAction::ACTION_COMMENT, $act);
            self::assertNotSame(SaAction::ACTION_COLLECT, $act);
        }
    }

    public function test_script_request_path_inputs_contract_separation(): void
    {
        $req = new SaScriptRequest([
            'request_id' => 'req_test_1',
            'action_tag_key' => 'facebook.group.post',
            'site_key' => 'facebook',
            'site_base_url' => 'https://www.facebook.com',
            'path_key' => 'group',
            'url_pattern' => '/groups/{group_id}',
            'path_inputs' => [
                ['key' => 'group_id', 'type' => 'string', 'required' => true],
            ],
            'action_key' => 'post',
            'request_type' => 'create',
            'status' => 'pending',
        ]);

        self::assertSame('facebook.group.post', $req->action_tag_key);
        self::assertSame('facebook', $req->site_key);
        self::assertSame('https://www.facebook.com', $req->site_base_url);
        self::assertSame('/groups/{group_id}', $req->url_pattern);
        self::assertIsArray($req->path_inputs);
        self::assertCount(1, $req->path_inputs);
        self::assertSame('group_id', $req->path_inputs[0]['key']);

        // Verify Path Inputs do NOT contain Script Variables (post_content, comment_text, images, etc.)
        $inputKeys = array_column($req->path_inputs, 'key');
        self::assertNotContains('post_content', $inputKeys);
        self::assertNotContains('comment_text', $inputKeys);
        self::assertNotContains('images', $inputKeys);
    }
}
