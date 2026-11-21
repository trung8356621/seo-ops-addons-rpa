<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignContentStatus;
use Omnichannel\Addons\SocialAutomation\Enums\CampaignStatus;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaign;
use Omnichannel\Addons\SocialAutomation\Models\SaCampaignContent;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

/**
 * Advances next_recheck_at for waiting_resources campaigns when Client/Browser
 * becomes eligible — does NOT dispatch jobs. Scheduler/recheckDueCampaigns owns dispatch.
 */
final class CampaignResourceRecheckNudge
{
    /** Minimum seconds between nudges per campaign (avoids heartbeat spam). */
    public const MIN_INTERVAL_SECONDS = 60;

    public const META_LAST_NUDGE_AT = 'resource_nudge_at';

    /**
     * @param  list<int>  $accountIds
     * @return array{nudged: int, skipped: int}
     */
    public function nudgeForAccounts(
        string $installationId,
        array $accountIds,
        ?DateTimeInterface $now = null,
    ): array {
        if (! SocialAutomationSchema::campaignsReady()) {
            return ['nudged' => 0, 'skipped' => 0];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $accountIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === [] || trim($installationId) === '') {
            return ['nudged' => 0, 'skipped' => 0];
        }

        $now = $now ?? new DateTimeImmutable('now');
        $nowTs = $now->getTimestamp();
        $nudged = 0;
        $skipped = 0;

        $campaigns = SaCampaign::query()
            ->where('installation_id', $installationId)
            ->where('status', CampaignStatus::Running->value)
            ->whereNotNull('next_recheck_at')
            ->where('next_recheck_at', '>', $now)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($campaigns as $campaign) {
            if (! $campaign instanceof SaCampaign) {
                continue;
            }
            if ((int) ($campaign->plan_version ?? 1) >= 2) {
                $skipped++;
                continue;
            }

            $hasWaiting = SaCampaignContent::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', CampaignContentStatus::WaitingResources->value)
                ->where(function ($q) use ($ids): void {
                    $q->whereIn('assigned_account_id', $ids);
                })
                ->exists();

            if (! $hasWaiting) {
                // Also match meta pin resolved onto assigned_account_id only — contents without
                // assigned_account_id are not nudged (resolveRequiredAccountId persists pin on dispatch).
                $skipped++;
                continue;
            }

            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $lastNudge = trim((string) ($meta[self::META_LAST_NUDGE_AT] ?? ''));
            if ($lastNudge !== '') {
                $lastTs = strtotime($lastNudge);
                if ($lastTs !== false && ($nowTs - $lastTs) < self::MIN_INTERVAL_SECONDS) {
                    $skipped++;
                    continue;
                }
            }

            $meta[self::META_LAST_NUDGE_AT] = $now instanceof DateTimeImmutable
                ? $now->format(DATE_ATOM)
                : (new DateTimeImmutable('@'.$nowTs))->format(DATE_ATOM);
            $campaign->meta = $meta;
            $campaign->next_recheck_at = $now;
            $campaign->save();
            $nudged++;
        }

        return ['nudged' => $nudged, 'skipped' => $skipped];
    }
}
