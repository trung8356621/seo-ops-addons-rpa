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

    public static function settingsReady(): bool
    {
        return self::has('sa_settings');
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
     * Campaign V2 Multi-Tag Draft tables & columns present.
     */
    public static function campaignV2Ready(): bool
    {
        if (! self::campaignsReady()) {
            return false;
        }
        try {
            $conn = SocialAutomationServiceConfig::CONNECTION;

            return (bool) @Schema::connection($conn)->hasColumn('sa_campaigns', 'plan_version')
                && self::has('sa_campaign_action_tags')
                && self::has('sa_campaign_content_assignments');
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

    /**
     * Keep Alive delivery ACK tables (Phase 3B.5A).
     */
    public static function keepaliveAckReady(): bool
    {
        return self::keepaliveFeedReady()
            && self::has('sa_keepalive_event_ack_cursors')
            && self::has('sa_keepalive_artifact_acks');
    }

    /**
     * Keep Alive Socket ticket route_generation + jti tables (Phase 3B.6B).
     */
    public static function keepaliveSocketReady(): bool
    {
        return self::keepaliveReady()
            && self::has('sa_keepalive_route_states')
            && self::has('sa_keepalive_socket_ticket_jtis');
    }

    /**
     * Keep Alive Socket notify outbox (Phase 3B.6C).
     */
    public static function keepaliveNotifyReady(): bool
    {
        return self::keepaliveSocketReady()
            && self::has('sa_keepalive_notify_outbox');
    }
}
