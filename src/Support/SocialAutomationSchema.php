<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class SocialAutomationSchema
{
    public static function has(string $table): bool
    {
        try {
            return (bool) @Schema::connection(SocialAutomationServiceConfig::CONNECTION)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    public static function agentsReady(): bool
    {
        return self::has('sa_agents') && self::has('sa_browser_sessions') && self::has('sa_accounts');
    }

    public static function jobsReady(): bool
    {
        return self::has('sa_tasks') && self::has('sa_jobs');
    }

    public static function schedulesReady(): bool
    {
        return self::has('sa_schedules') && self::has('sa_schedule_triggers');
    }

    public static function campaignsReady(): bool
    {
        return self::has('sa_campaigns')
            && self::has('sa_campaign_contents')
            && self::has('sa_campaign_account_assignments')
            && self::jobsReady();
    }

    /**
     * Campaign Shortcode V2 columns present (additive migration applied).
     */
    public static function campaignShortcodeReady(): bool
    {
        if (! self::campaignsReady()) {
            return false;
        }
        try {
            $conn = SocialAutomationServiceConfig::CONNECTION;

            return (bool) @Schema::connection($conn)->hasColumn('sa_campaigns', 'shortcode_mapping')
                && (bool) @Schema::connection($conn)->hasColumn('sa_campaigns', 'shortcode_mapping_version')
                && (bool) @Schema::connection($conn)->hasColumn('sa_campaign_contents', 'source_format')
                && (bool) @Schema::connection($conn)->hasColumn('sa_campaign_contents', 'raw_source')
                && (bool) @Schema::connection($conn)->hasColumn('sa_campaign_contents', 'mapping_version');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Keep Alive Node registration + explicit Campaign assignment tables.
     */
    public static function keepaliveReady(): bool
    {
        return self::has('sa_keepalive_nodes')
            && self::has('sa_keepalive_campaign_assignments')
            && self::has('sa_agents')
            && self::has('sa_campaigns');
    }

    /**
     * Keep Alive snapshot/event feed tables (Phase 3B.3).
     */
    public static function keepaliveFeedReady(): bool
    {
        return self::keepaliveReady()
            && self::has('sa_keepalive_campaign_events')
            && self::has('sa_keepalive_campaign_sequences');
    }
}
