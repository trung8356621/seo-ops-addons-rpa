<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use Omnichannel\Addons\SocialAutomation\Models\SaAccount;
use Omnichannel\Addons\SocialAutomation\Models\SaSite;

/**
 * Resolve Client Account `site_url` from Action Tags Site Registry.
 *
 * Policy (unified Base URL — CP18D.3A):
 * 1. Prefer SaSite.base_url for site_key matching Account.platform (canonical).
 * 2. Never use SaSite.test_base_url (legacy column; not a runtime fallback).
 * 3. Never invent a URL from site_key.
 * 4. Fallback to persisted Account.site_url/domain only when Registry has no usable base_url
 *    (legacy WordPress/custom inventory rows). Does not write back to Account.
 * 5. Site Registry is connection-global (sa_sites has no installation_id); Account inventory
 *    remains installation-scoped by the caller.
 */
final class AccountSiteUrlResolver
{
    /**
     * @var array<string, ?string>|null lowercase site_key => validated http(s) URL or null when site exists without usable base_url
     */
    private ?array $registryUrls = null;

    /**
     * @param  array<string, ?string>|null  $registryUrls  Optional preloaded map (tests / batch warm)
     */
    public function __construct(?array $registryUrls = null)
    {
        $this->registryUrls = $registryUrls;
    }

    /**
     * Load Site Registry once for an inventory batch (avoids N+1).
     *
     * @param  iterable<int, object|array<string, mixed>>  $sites
     */
    public function warmFromSites(iterable $sites): void
    {
        $map = [];
        foreach ($sites as $site) {
            $key = strtolower(trim((string) $this->siteAttr($site, 'key')));
            if ($key === '') {
                continue;
            }
            $map[$key] = $this->validateHttpUrl($this->siteAttr($site, 'base_url'));
        }
        $this->registryUrls = $map;
    }

    /**
     * Ensure registry map is available (single query when not preloaded).
     */
    public function ensureWarmed(): void
    {
        if ($this->registryUrls !== null) {
            return;
        }

        try {
            $sites = SaSite::query()->get(['key', 'base_url']);
            $this->warmFromSites($sites);
        } catch (\Throwable) {
            $this->registryUrls = [];
        }
    }

    /**
     * Resolve site_url for one Account without mutating persistence.
     */
    public function resolveForAccount(SaAccount $account): ?string
    {
        $this->ensureWarmed();

        $platform = strtolower(trim((string) ($account->platform ?? '')));
        $registryUrl = ($platform !== '' && array_key_exists($platform, $this->registryUrls ?? []))
            ? ($this->registryUrls[$platform] ?? null)
            : null;

        if (is_string($registryUrl) && $registryUrl !== '') {
            return $registryUrl;
        }

        // Known site without usable base_url → do not invent; still allow legacy account fallback.
        return $this->validateHttpUrl($account->getSiteUrl());
    }

    /**
     * Validate / normalize to http(s) URL. Returns null when empty or invalid.
     * Does not guess domains from site keys. Does not substitute localhost.
     */
    public function validateHttpUrl(mixed $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim((string) $url);
        if ($trimmed === '') {
            return null;
        }

        // Require explicit http(s) — do not silently prepend schemes for Client Browser URL.
        if (! preg_match('~^https?://~i', $trimmed)) {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($trimmed);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (empty($parts['host'])) {
            return null;
        }

        return rtrim($trimmed, '/');
    }

    /**
     * @param  object|array<string, mixed>  $site
     */
    private function siteAttr(object|array $site, string $attr): mixed
    {
        if (is_array($site)) {
            return $site[$attr] ?? null;
        }

        return $site->{$attr} ?? null;
    }
}
