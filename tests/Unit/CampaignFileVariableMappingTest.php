<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignScriptVariableSchema;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodePipeline;
use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeTypedValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CampaignFileVariableMappingTest extends TestCase
{
    public function test_text_and_files_mapping_accepted(): void
    {
        $schema = (new CampaignScriptVariableSchema)->fromManifest([
            'variables' => [
                ['id' => '1', 'key' => 'text', 'type' => 'text', 'required' => true],
                ['id' => '2', 'key' => 'files', 'type' => 'files', 'required' => true],
            ],
        ]);
        self::assertTrue($schema['ok']);
        $vars = $schema['variables'];
        self::assertSame('string', $vars['text']['type']);
        self::assertSame('files', $vars['files']['type']);
        self::assertTrue((new CampaignScriptVariableSchema)->isShortcodeSupported('files'));
        self::assertTrue((new CampaignScriptVariableSchema)->toStaffItem($vars['files'])['shortcode_compatible']);

        $map = (new CampaignShortcodeMappingValidator)->validate([
            'entries' => [
                ['shortcode' => 'text', 'variable' => 'text'],
                ['shortcode' => 'files', 'variable' => 'files'],
            ],
        ], $vars);
        self::assertTrue($map['ok'], implode(',', $map['errors'] ?? []));
        self::assertCount(2, $map['mapping']['entries']);
    }

    public function test_typed_file_and_files_path_contract(): void
    {
        $t = new CampaignShortcodeTypedValidator;
        $fileVar = [
            'key' => 'img', 'type' => 'file', 'required' => true, 'allow_multiple' => false,
            'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '1',
        ];
        $filesVar = [
            'key' => 'files', 'type' => 'files', 'required' => true, 'allow_multiple' => false,
            'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '2',
        ];

        $okFile = $t->validate(['img' => 'D:\\qa\\sandbox\\photo.jpg'], ['img' => $fileVar]);
        self::assertTrue($okFile['ok']);
        self::assertSame('D:\\qa\\sandbox\\photo.jpg', $okFile['input']['img']);

        $okFiles = $t->validate([
            'files' => ['D:\\qa\\a.jpg', 'D:\\qa\\b.png'],
        ], ['files' => $filesVar]);
        self::assertTrue($okFiles['ok']);
        self::assertSame(['D:\\qa\\a.jpg', 'D:\\qa\\b.png'], $okFiles['input']['files']);

        $fromJson = $t->validate([
            'files' => '["D:\\\\qa\\\\c.webp"]',
        ], ['files' => $filesVar]);
        self::assertTrue($fromJson['ok']);
        self::assertSame(['D:\\qa\\c.webp'], $fromJson['input']['files']);

        $rejectProse = $t->validate(['img' => 'hello world'], ['img' => $fileVar]);
        self::assertFalse($rejectProse['ok']);
        self::assertContains('variable_file_path_invalid:img', $rejectProse['errors']);

        $rejectUrl = $t->validate(['img' => 'https://cdn.example/a.jpg'], ['img' => $fileVar]);
        self::assertFalse($rejectUrl['ok']);

        $rejectRelative = $t->validate(['img' => 'images\\a.jpg'], ['img' => $fileVar]);
        self::assertFalse($rejectRelative['ok']);

        $rejectFake = $t->validate(['img' => 'C:\\fakepath\\a.jpg'], ['img' => $fileVar]);
        self::assertFalse($rejectFake['ok']);
    }

    public function test_pipeline_maps_text_and_files_shortcodes(): void
    {
        $vars = [
            'text' => [
                'key' => 'text', 'type' => 'string', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '1',
            ],
            'files' => [
                'key' => 'files', 'type' => 'files', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '2',
            ],
        ];
        $mapping = ['entries' => [
            ['shortcode' => 'text', 'variable' => 'text'],
            ['shortcode' => 'files', 'variable' => 'files'],
        ]];
        $raw = "[text]QA caption[/text]\n[files]D:\\qa\\sandbox\\post.jpg[/files]";
        $r = (new CampaignShortcodePipeline)->process($raw, $mapping, $vars);
        self::assertTrue($r['ok'], implode(',', $r['errors'] ?? []));
        self::assertSame('QA caption', $r['input']['text']);
        self::assertSame(['D:\\qa\\sandbox\\post.jpg'], $r['input']['files']);
        self::assertIsArray($r['input']['files']);
        self::assertFalse(is_string($r['input']['files']));
    }

    public function test_public_job_input_preserves_files_string_array(): void
    {
        $orchestratorClass = new ReflectionClass(
            \Omnichannel\Addons\SocialAutomation\Orchestration\CampaignOrchestrator::class
        );
        $method = $orchestratorClass->getMethod('publicJobInput');
        $method->setAccessible(true);

        // Avoid full constructor deps: invoke on uninitialized instance via reflection newInstanceWithoutConstructor
        $orchestrator = $orchestratorClass->newInstanceWithoutConstructor();
        $input = [
            'text' => 'hello',
            'files' => ['D:\\qa\\a.jpg', 'D:\\qa\\b.jpg'],
            'meta' => ['nested' => 'x'],
        ];
        $out = $method->invoke($orchestrator, $input);
        self::assertSame('hello', $out['text']);
        self::assertSame(['D:\\qa\\a.jpg', 'D:\\qa\\b.jpg'], $out['files']);
        self::assertIsArray($out['files']);
        self::assertNotSame('D:\\qa\\a.jpg;D:\\qa\\b.jpg', $out['files']);
        self::assertSame(['nested' => 'x'], $out['meta']);
    }
}
