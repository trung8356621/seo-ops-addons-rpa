<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageDigest;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishResult;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackagePublishValidator;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptReleaseVersionPolicy;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Regression contracts for RPA Creator → SEO-OPS Save/Sync.
 * Covers version gaps, pending_media sequencing, idempotent retry, metadata-only Saves.
 */
final class ScriptSyncSavePolicyContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private function scriptPayload(string $version, string $name = 'Demo', array $manifestExtra = []): array
    {
        return [
            'package_key' => 'demo.script.save',
            'kind' => 'script',
            'name' => $name,
            'version' => $version,
            'schema_version' => 1,
            'manifest' => array_merge([
                'script_id' => 'demo-script-save',
                'variables' => [],
                'steps' => [['id' => 's1', 'action' => 'CLICK_TARGET']],
                'video_demo' => [
                    'artifact_ref' => 'demo.webm',
                    'test_run_id' => 'run-1',
                    'reviewed' => true,
                    'audio' => false,
                    'desktop_capture' => false,
                ],
            ], $manifestExtra),
            'dependencies' => [],
        ];
    }

    public function test_first_save_and_gapped_new_version_validate(): void
    {
        $validator = new PackagePublishValidator;
        $policy = new ScriptReleaseVersionPolicy;

        $first = $validator->validate($this->scriptPayload('1'));
        self::assertTrue($first['ok'], implode(',', $first['errors'] ?? []));
        self::assertSame([], $policy->validateNext(null, '1'));

        $gapped = $validator->validate($this->scriptPayload('3'));
        self::assertTrue($gapped['ok'], implode(',', $gapped['errors'] ?? []));
        self::assertSame([], $policy->validateNext('1', '3'));
        self::assertNotSame($first['data']['content_digest'], $gapped['data']['content_digest']);
    }

    public function test_metadata_only_save_keeps_execution_stable_but_policy_allows(): void
    {
        $validator = new PackagePublishValidator;
        $policy = new ScriptReleaseVersionPolicy;

        $a = $validator->validate($this->scriptPayload('1', 'Demo A'));
        $b = $validator->validate($this->scriptPayload('2', 'Demo B renamed'));
        self::assertTrue($a['ok'] && $b['ok']);
        self::assertNotSame($a['data']['content_digest'], $b['data']['content_digest']);

        $execA = \Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptExecutionDigest::fromManifest($a['data']['manifest']);
        $execB = \Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptExecutionDigest::fromManifest($b['data']['manifest']);
        self::assertTrue(PackageDigest::equals($execA, $execB));
        self::assertSame([], $policy->validateCanonicalChange($execA, $execB));
        self::assertSame([], $policy->validateNext('1', '2'));
    }

    public function test_same_version_same_digest_is_idempotent_shape(): void
    {
        $validator = new PackagePublishValidator;
        $payload = $this->scriptPayload('1');
        $a = $validator->validate($payload);
        $b = $validator->validate(array_merge($payload, ['content_digest' => $a['data']['content_digest']]));
        self::assertTrue($a['ok'] && $b['ok']);
        self::assertTrue(PackageDigest::equals($a['data']['content_digest'], $b['data']['content_digest']));

        $result = new PackagePublishResult(
            PackagePublishResult::OUTCOME_IDEMPOTENT,
            release: [
                'package_key' => 'demo.script.save',
                'kind' => 'script',
                'name' => 'Demo',
                'version' => '1',
                'content_digest' => $a['data']['content_digest'],
                'schema_version' => 1,
                'status' => 'pending_media',
                'idempotent' => true,
            ],
        );
        $json = $result->toArray();
        self::assertTrue($json['ok']);
        self::assertSame('idempotent', $json['outcome']);
        self::assertSame(200, $result->httpStatus());
    }

    public function test_existing_version_different_digest_returns_conflict_with_suggested_version(): void
    {
        $result = new PackagePublishResult(
            PackagePublishResult::OUTCOME_CONFLICT,
            errors: ['version_digest_conflict'],
            existingDigest: str_repeat('ab', 32),
            suggestedVersion: '3',
        );
        $json = $result->toArray();
        self::assertFalse($json['ok']);
        self::assertSame(409, $result->httpStatus());
        self::assertSame(['version_digest_conflict'], $json['errors']);
        self::assertSame('3', $json['suggested_version']);
        self::assertSame(str_repeat('ab', 32), $json['existing_digest']);
    }

    public function test_sequence_conflict_includes_suggested_next_from_pending_media_head(): void
    {
        $policy = new ScriptReleaseVersionPolicy;
        // published=1, pending_media=2 → highest is 2; Creator sending 2 again is not sequence (handled as existing).
        // Sending 1 after 2 is sequence conflict; suggested = 3.
        self::assertSame(['script_version_sequence_conflict'], $policy->validateNext('2', '1'));
        self::assertSame('3', $policy->nextVersionAfter('2'));

        $pendingHead = $policy->pickHighestOrdinal([
            (object) ['version' => '1', 'status' => 'published'],
            (object) ['version' => '2', 'status' => 'pending_media'],
        ]);
        self::assertSame('2', $pendingHead->version);
        self::assertSame([], $policy->validateNext((string) $pendingHead->version, '4'));
    }

    public function test_catalog_and_registry_include_pending_media_in_revision_head(): void
    {
        $registry = (string) file_get_contents($this->root.'/src/PackageRegistry/PackageRegistryService.php');
        $catalog = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptCatalogService.php');
        $policy = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptReleaseVersionPolicy.php');

        self::assertStringContainsString("whereIn('status', ['published', 'pending_media'])", $registry);
        self::assertStringContainsString('latestScriptRevision', $registry);
        self::assertStringContainsString('lockForUpdate', $registry);
        self::assertStringContainsString('suggestedVersion', $registry);
        self::assertStringNotContainsString('validateCanonicalChange(', $registry);

        self::assertStringContainsString("whereIn('status', ['published', 'pending_media'])", $catalog);
        self::assertStringContainsString('pickHighestOrdinal', $catalog);
        self::assertStringContainsString("'status' => (string) \$latest->status", $catalog);

        self::assertStringContainsString('gaps are allowed', $policy);
        self::assertStringContainsString('Metadata / demo-only Saves are allowed', $policy);
    }

    public function test_network_retry_same_digest_plans_as_skip_when_catalog_matches(): void
    {
        $planner = new ScriptSyncPlanner;
        $digest = str_repeat('cd', 32);
        $row = [
            'script_id' => 'demo-script-save',
            'package_key' => 'demo.script.save',
            'version' => '2',
            'content_digest' => $digest,
            'lineage' => [],
            'assets_complete' => false,
        ];
        $plan = $planner->plan([$row], [$row]);
        self::assertSame([], $plan['push_to_remote']);
        self::assertSame([], $plan['conflicts']);
        self::assertSame([['script_id' => 'demo-script-save', 'reason' => 'same_digest']], $plan['skip']);
    }

    public function test_media_attach_still_publishes_pending_media_release(): void
    {
        $media = (string) file_get_contents($this->root.'/src/PackageRegistry/ScriptReleaseMediaService.php');
        self::assertStringContainsString("'status' => 'published'", $media);
        self::assertStringContainsString('script_assets_incomplete', $media);
        self::assertStringContainsString('updateOrCreate', $media);
        self::assertStringContainsString('KIND_VIDEO_DEMO', $media);
        self::assertStringContainsString('KIND_THUMBNAIL', $media);
    }

    public function test_version_remains_in_canonical_digest_so_server_does_not_rewrite_client_version(): void
    {
        $validator = new PackagePublishValidator;
        $v1 = $validator->validate($this->scriptPayload('1'));
        $v2 = $validator->validate($this->scriptPayload('2'));
        self::assertTrue($v1['ok'] && $v2['ok']);
        // Same manifest, different version → different content_digest (version is canonical).
        self::assertNotSame($v1['data']['content_digest'], $v2['data']['content_digest']);

        $source = (string) file_get_contents($this->root.'/src/PackageRegistry/PackagePublishValidator.php');
        self::assertStringContainsString("'version' => \$version", $source);
    }
}
