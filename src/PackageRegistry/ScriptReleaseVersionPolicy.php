<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * Script release versions distinguish Save revisions.
 * Server accepts any unused integer revision that advances past the latest
 * published/pending_media release; gaps are allowed (1→3 OK). Exact +1 is not required.
 */
final class ScriptReleaseVersionPolicy
{
    /** @return array{ok: bool, ordinal?: int, legacy?: bool, error?: string} */
    public function parse(string $version): array
    {
        $version = trim($version);
        if (preg_match('/^[1-9][0-9]*$/', $version) === 1) {
            return ['ok' => true, 'ordinal' => (int) $version, 'legacy' => false];
        }
        if (preg_match('/^([1-9][0-9]*)\.0\.0$/', $version, $match) === 1) {
            return ['ok' => true, 'ordinal' => (int) $match[1], 'legacy' => true];
        }

        return ['ok' => false, 'error' => 'script_version_compatibility_unsupported'];
    }

    /**
     * Validate that $incoming can be stored as a new revision after $previous.
     * Same-version idempotency / digest conflict is handled by the registry before this runs.
     *
     * @return list<string>
     */
    public function validateNext(?string $previous, string $incoming): array
    {
        $next = $this->parse($incoming);
        if (! ($next['ok'] ?? false)) {
            return [(string) $next['error']];
        }

        // New saves must use integer revision form (N), not legacy N.0.0.
        if ($next['legacy'] ?? false) {
            return ['script_version_sequence_conflict'];
        }

        if ($previous === null) {
            // First Save: any valid integer revision ≥ 1 is accepted.
            return [];
        }

        $prior = $this->parse($previous);
        if (! ($prior['ok'] ?? false)) {
            return [(string) $prior['error']];
        }

        // Must advance; gaps allowed. Do not require exact +1.
        return ($next['ordinal'] ?? 0) <= ($prior['ordinal'] ?? 0)
            ? ['script_version_sequence_conflict']
            : [];
    }

    /**
     * Metadata / demo-only Saves are allowed even when executable content is unchanged.
     * Widget invalidation still uses ScriptExecutionDigest::executionChanged separately.
     *
     * @return list<string>
     */
    public function validateCanonicalChange(?string $previousExecutionDigest, string $incomingExecutionDigest): array
    {
        unset($previousExecutionDigest, $incomingExecutionDigest);

        return [];
    }

    public function nextVersionAfter(?string $previous): string
    {
        if ($previous === null || trim($previous) === '') {
            return '1';
        }
        $prior = $this->parse($previous);
        if (! ($prior['ok'] ?? false)) {
            return '1';
        }

        return (string) (((int) ($prior['ordinal'] ?? 0)) + 1);
    }

    /**
     * Pick the release with the highest parseable ordinal from a list.
     *
     * @param  iterable<mixed>  $releases  objects/arrays with a version property/key
     */
    public function pickHighestOrdinal(iterable $releases): mixed
    {
        $best = null;
        $bestOrdinal = -1;
        foreach ($releases as $release) {
            $version = null;
            if (is_object($release) && isset($release->version)) {
                $version = (string) $release->version;
            } elseif (is_array($release) && isset($release['version'])) {
                $version = (string) $release['version'];
            }
            if ($version === null) {
                continue;
            }
            $parsed = $this->parse($version);
            if (! ($parsed['ok'] ?? false)) {
                continue;
            }
            $ordinal = (int) ($parsed['ordinal'] ?? 0);
            if ($ordinal > $bestOrdinal) {
                $bestOrdinal = $ordinal;
                $best = $release;
            }
        }

        return $best;
    }
}
