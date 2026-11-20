<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMockGenerator;
use PHPUnit\Framework\TestCase;

final class CampaignShortcodeMockGeneratorTest extends TestCase
{
    public function test_generates_unique_refs_through_pipeline(): void
    {
        $vars = [
            'title' => [
                'key' => 'title', 'type' => 'string', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => 200, 'pattern' => null, 'enum' => null, 'id' => '1',
            ],
        ];
        $mapping = ['entries' => [['shortcode' => 'body', 'variable' => 'title']]];
        $gen = new CampaignShortcodeMockGenerator;
        $r = $gen->generate(3, $mapping, $vars);
        self::assertTrue($r['ok']);
        self::assertCount(3, $r['articles']);
        $refs = array_column($r['articles'], 'external_ref');
        self::assertCount(3, array_unique($refs));
        foreach ($r['articles'] as $article) {
            self::assertStringContainsString('[body]', $article['raw_source']);
            self::assertArrayHasKey('title', $article['input']);
            self::assertNotSame('', $article['input']['title']);
        }
    }

    public function test_rejects_pattern_constraint_and_file_type(): void
    {
        $gen = new CampaignShortcodeMockGenerator;
        $patternVars = [
            'code' => [
                'key' => 'code', 'type' => 'string', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => '^[A-Z]{3}$', 'enum' => null, 'id' => '1',
            ],
        ];
        $bad = $gen->generate(1, ['entries' => [['shortcode' => 'body', 'variable' => 'code']]], $patternVars);
        self::assertFalse($bad['ok']);

        $fileVars = [
            'img' => [
                'key' => 'img', 'type' => 'file', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '1',
            ],
        ];
        $fileMap = (new \Omnichannel\Addons\SocialAutomation\Domain\CampaignShortcodeMappingValidator)
            ->validate(['entries' => [['shortcode' => 'body', 'variable' => 'img']]], $fileVars);
        self::assertFalse($fileMap['ok']);
    }

    public function test_max_batch_bound(): void
    {
        $vars = [
            'title' => [
                'key' => 'title', 'type' => 'string', 'required' => true, 'allow_multiple' => false,
                'min' => null, 'max' => null, 'minLength' => null, 'maxLength' => null, 'pattern' => null, 'enum' => null, 'id' => '1',
            ],
        ];
        $gen = new CampaignShortcodeMockGenerator;
        $r = $gen->generate(51, ['entries' => [['shortcode' => 'body', 'variable' => 'title']]], $vars);
        self::assertFalse($r['ok']);
        self::assertContains('mock_count_exceeds_max', $r['errors']);
    }
}
