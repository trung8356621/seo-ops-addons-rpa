<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeTypedValidator;
use PHPUnit\Framework\TestCase;

final class CampaignShortcodeTypedValidatorTest extends TestCase
{
    public function test_requires_all_script_required_variables_not_only_mapped(): void
    {
        $schema = (new CampaignScriptVariableSchema)->fromManifest([
            'variables' => [
                ['id' => '1', 'key' => 'title', 'type' => 'text', 'required' => true],
                ['id' => '2', 'key' => 'url', 'type' => 'url', 'required' => true],
            ],
        ]);
        self::assertTrue($schema['ok']);
        $vars = $schema['variables'];

        $map = (new CampaignShortcodeMappingValidator)->validate([
            'entries' => [['shortcode' => 'body', 'variable' => 'title']],
        ], $vars);
        self::assertFalse($map['ok']);
        self::assertContains('required_variable_unmapped:url', $map['errors']);

        $typed = new CampaignShortcodeTypedValidator;
        $missing = $typed->validate(['title' => 'Only title'], $vars);
        self::assertFalse($missing['ok']);
        self::assertContains('required_variable_missing:url', $missing['errors']);
    }

    public function test_typed_coercion_and_fail_closed(): void
    {
        $vars = [
            'n' => ['key' => 'n', 'type' => 'integer', 'required' => true, 'min' => 1, 'max' => 10, 'allow_multiple' => false, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => null],
            'flag' => ['key' => 'flag', 'type' => 'boolean', 'required' => false, 'allow_multiple' => false, 'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => null],
            'file' => ['key' => 'file', 'type' => 'file', 'required' => false, 'allow_multiple' => false, 'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => null],
        ];
        $t = new CampaignShortcodeTypedValidator;
        $ok = $t->validate(['n' => '3', 'flag' => 'true'], [
            'n' => $vars['n'],
            'flag' => $vars['flag'],
        ]);
        self::assertTrue($ok['ok']);
        self::assertSame(3, $ok['input']['n']);
        self::assertTrue($ok['input']['flag']);

        $badFile = $t->validate(['file' => 'x'], ['file' => $vars['file']]);
        self::assertFalse($badFile['ok']);
    }

    public function test_pipeline_body_maps_to_title(): void
    {
        $vars = [
            'title' => [
                'key' => 'title', 'type' => 'string', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => 't1',
            ],
        ];
        $mapping = ['entries' => [['shortcode' => 'body', 'variable' => 'title']]];
        $pipe = new CampaignShortcodePipeline;
        $r = $pipe->process('[body]POC Draft Title[/body]', $mapping, $vars);
        self::assertTrue($r['ok']);
        self::assertSame('POC Draft Title', $r['input']['title']);
        self::assertArrayNotHasKey('body', $r['input']);
    }
}
