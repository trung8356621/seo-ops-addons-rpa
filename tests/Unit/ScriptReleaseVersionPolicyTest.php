<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseVersionPolicy;
use PHPUnit\Framework\TestCase;

final class ScriptReleaseVersionPolicyTest extends TestCase
{
    public function test_first_save_accepts_any_valid_integer_revision(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateNext(null, '1'));
        self::assertSame([], $policy->validateNext(null, '3'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext(null, '1.0.0'));
    }

    public function test_new_version_allows_gaps_but_not_regression(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateNext('1', '2'));
        self::assertSame([], $policy->validateNext('1', '3'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('2', '2'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('2', '1'));
    }

    public function test_legacy_previous_maps_ordinal_and_incoming_must_be_integer(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateNext('1.0.0', '2'));
        self::assertSame([], $policy->validateNext('1.0.0', '5'));
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('1.0.0', '1'));
        self::assertSame(
            ['script_version_compatibility_unsupported'],
            $policy->validateNext('1.2.3', '2'),
        );
    }

    public function test_unchanged_execution_content_does_not_block_save(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame([], $policy->validateCanonicalChange('abc', 'ABC'));
        self::assertSame([], $policy->validateCanonicalChange('abc', 'def'));
        self::assertSame([], $policy->validateCanonicalChange(null, 'abc'));
    }

    public function test_next_version_after_and_highest_ordinal(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        self::assertSame('1', $policy->nextVersionAfter(null));
        self::assertSame('3', $policy->nextVersionAfter('2'));
        self::assertSame('2', $policy->nextVersionAfter('1.0.0'));

        $rows = [
            (object) ['version' => '1', 'id' => 1],
            (object) ['version' => '3', 'id' => 3],
            (object) ['version' => '2', 'id' => 2],
        ];
        $best = $policy->pickHighestOrdinal($rows);
        self::assertSame('3', $best->version);
    }
}
