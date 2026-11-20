<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeParser;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeRichText;
use PHPUnit\Framework\TestCase;

final class CampaignShortcodeParserTest extends TestCase
{
    public function test_parses_paired_tags_and_preserves_inner_text(): void
    {
        $p = new CampaignShortcodeParser;
        $r = $p->parse("[body]Hello *world*[/body]\n[title]T[/title]", ['body', 'title']);
        self::assertTrue($r['ok']);
        self::assertSame('Hello *world*', $r['values']['body']);
        self::assertSame('T', $r['values']['title']);
    }

    public function test_rejects_malformed_unmatched_duplicate_outside_text(): void
    {
        $p = new CampaignShortcodeParser;
        self::assertFalse($p->parse('[body]x', ['body'])['ok']);
        self::assertFalse($p->parse('[body]x[/body] leftover', ['body'])['ok']);
        self::assertFalse($p->parse('[body]a[/body][body]b[/body]', ['body'])['ok']);
        self::assertFalse($p->parse('[unknown]x[/unknown]', ['body'])['ok']);
        self::assertFalse($p->parse('[body][title]x[/title][/body]', ['body', 'title'])['ok']);
    }

    public function test_richtext_rejects_html_and_tiptap_json(): void
    {
        $rt = new CampaignShortcodeRichText;
        self::assertFalse($rt->assertCanonical('<p>Hi</p>')['ok']);
        self::assertFalse($rt->assertCanonical('{"type":"doc","content":[]}')['ok']);
        self::assertFalse($rt->assertCanonical('<strong>Bold</strong>')['ok']);
        self::assertTrue($rt->assertCanonical('[body]Plain with *asterisks*[/body]')['ok']);
    }
}
