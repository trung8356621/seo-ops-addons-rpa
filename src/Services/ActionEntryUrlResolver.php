<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Services;

use Omnichannel\Addons\SocialAutomation\Domain\AccountSiteUrlResolver;
use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaActionTag;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Models\SaPath;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Resolves the authoritative Action entry URL (`execution_context.resolved_url`)
 * for Campaign Action Jobs from SaActionTag -> SaSite / Account site_url + SaPath.
 *
 * Never invents URLs, never exposes credentials, and rejects authentication paths
 * or cross-origin / unsafe destinations.
 */
final class ActionEntryUrlResolver
{
    public function __construct(
        private readonly AccountSiteUrlResolver $accountUrls = new AccountSiteUrlResolver,
    ) {}

    /**
     * @return array{
     *   base_url: string|null,
     *   url_pattern: string,
     *   resolved_url: string,
     *   is_authentication: bool,
     *   site_key: string|null,
     *   action_tag_key: string
     * }|null
     */
    public function resolveForJob(SaJob $job, ?SaAccount $account = null, ?SaCampaign $campaign = null): ?array
    {
        if (! SocialAutomationSchema::has('sa_action_tags')
            || ! SocialAutomationSchema::has('sa_sites')
            || ! SocialAutomationSchema::has('sa_paths')) {
            return null;
        }

        $payload = is_array($job->payload ?? null) ? $job->payload : [];
        $tagKey = $this->extractActionTagKey($payload, $job, $campaign);
        if ($tagKey === null) {
            return null;
        }

        $tag = SaActionTag::query()
            ->with(['site', 'path'])
            ->where('tag_key', $tagKey)
            ->first();
        if (! $tag instanceof SaActionTag) {
            return null;
        }

        $site = $this->resolveSiteForTag($tag);
        if ($account instanceof SaAccount && $site instanceof SaSite) {
            if (strcasecmp((string) $account->platform, (string) $site->key) !== 0) {
                return null;
            }
        }

        $path = $this->resolvePathForTag($tag, $site);
        if (! $path instanceof SaPath) {
            return null;
        }

        // Never confuse Authentication URL with Action execution URL.
        if ((bool) ($path->is_authentication ?? false)) {
            return null;
        }

        $pattern = trim((string) ($path->url_pattern ?? ''));
        if ($pattern === '') {
            return null;
        }

        $rawBase = $site instanceof SaSite ? $site->canonicalBaseUrl() : null;
        if ($rawBase === null && $account instanceof SaAccount) {
            $rawBase = $this->accountUrls->resolveForAccount($account);
        }
        $baseUrl = $rawBase !== null ? $this->validateSafeHttpUrl($rawBase) : null;
        if ($rawBase !== null && $baseUrl === null) {
            return null;
        }

        $resolvedUrl = $this->resolveDestinationUrl($baseUrl, $pattern);
        if ($resolvedUrl === null) {
            return null;
        }

        return [
            'base_url' => $baseUrl,
            'url_pattern' => $pattern,
            'resolved_url' => $resolvedUrl,
            'is_authentication' => false,
            'site_key' => $site?->key,
            'action_tag_key' => (string) $tag->tag_key,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractActionTagKey(array $payload, object $job, ?SaCampaign $campaign): ?string
    {
        $candidates = [
            $payload['action_tag_key'] ?? null,
            is_array($payload['resolved_script_context'] ?? null)
                ? ($payload['resolved_script_context']['target_action_tag_key'] ?? null)
                : null,
            $campaign?->script_id,
            $job->package_key ?? null,
        ];

        foreach ($candidates as $candidate) {
            $key = strtolower(trim((string) $candidate));
            if ($key !== '' && $key !== 'v2:multi-tag') {
                return $key;
            }
        }

        return null;
    }

    private function resolveSiteForTag(SaActionTag $tag): ?SaSite
    {
        if ($tag->relationLoaded('site') && $tag->site instanceof SaSite) {
            return $tag->site;
        }
        if ($tag->site_id) {
            $site = SaSite::query()->find((int) $tag->site_id);
            if ($site instanceof SaSite) {
                return $site;
            }
        }
        $siteKey = trim((string) ($tag->site_key ?: $tag->platform));

        return $siteKey !== '' ? SaSite::query()->where('key', $siteKey)->first() : null;
    }

    private function resolvePathForTag(SaActionTag $tag, ?SaSite $site): ?SaPath
    {
        if ($tag->relationLoaded('path') && $tag->path instanceof SaPath) {
            return $tag->path;
        }
        if ($tag->path_id) {
            $path = SaPath::query()->find((int) $tag->path_id);
            if ($path instanceof SaPath) {
                return $path;
            }
        }
        $pathKey = trim((string) ($tag->path_key ?: $tag->target));
        if ($site instanceof SaSite && $pathKey !== '') {
            return SaPath::query()
                ->where('site_id', $site->id)
                ->where('key', $pathKey)
                ->first();
        }

        return null;
    }

    private function resolveDestinationUrl(?string $baseUrl, string $pattern): ?string
    {
        if (str_starts_with($pattern, '//') || str_contains($pattern, '\\')) {
            return null;
        }

        if (preg_match('~^https?://~i', $pattern) === 1) {
            $abs = $this->validateSafeHttpUrl($pattern);
            if ($abs === null) {
                return null;
            }
            if ($baseUrl !== null && ! $this->sameOrigin($baseUrl, $abs)) {
                return null;
            }

            return $abs;
        }

        // Reject any other scheme (javascript:, data:, file:, ftp:, etc.)
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $pattern) === 1) {
            return null;
        }

        if ($baseUrl === null) {
            return null;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($pattern, '/');
    }

    private function validateSafeHttpUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || preg_match('~^https?://~i', $trimmed) !== 1) {
            return null;
        }
        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        // Reject embedded credentials (userinfo)
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return rtrim($trimmed, '/');
    }

    private function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (! is_array($pa) || ! is_array($pb)) {
            return false;
        }

        $schemeA = strtolower((string) ($pa['scheme'] ?? ''));
        $schemeB = strtolower((string) ($pb['scheme'] ?? ''));
        $hostA = strtolower((string) ($pa['host'] ?? ''));
        $hostB = strtolower((string) ($pb['host'] ?? ''));
        $portA = $pa['port'] ?? ($schemeA === 'https' ? 443 : 80);
        $portB = $pb['port'] ?? ($schemeB === 'https' ? 443 : 80);

        return $schemeA === $schemeB && $hostA === $hostB && $portA === $portB;
    }
}
