<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use Omnichannel\Addons\SocialAutomation\Domain\SessionSignalState;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for Session Signal two-way boolean + last-writer-wins.
 * Covers A–F logic without depending on login confirm / ACK / task verification.
 */
final class SessionSignalBooleanSyncTest extends TestCase
{
    public function test_a_client_true_becomes_seo_ops_true(): void
    {
        $merged = SessionSignalState::mergeLastWriteWins(
            ['present' => false, 'status' => 'absent', 'checked_at' => '2026-10-27T10:00:00Z', 'method' => null],
            ['present' => true, 'status' => 'present', 'checked_at' => '2026-10-27T10:00:05Z', 'method' => 'facebook_c_user_cookie'],
        );
        self::assertTrue($merged['present']);
        self::assertSame('present', $merged['status']);
        self::assertSame('2026-10-27T10:00:05Z', $merged['checked_at']);
    }

    public function test_b_seo_ops_false_wins_over_stale_client_true(): void
    {
        $merged = SessionSignalState::mergeLastWriteWins(
            ['present' => true, 'status' => 'present', 'checked_at' => '2026-10-27T10:00:00Z', 'method' => 'facebook_c_user_cookie'],
            ['present' => false, 'status' => 'absent', 'checked_at' => '2026-10-27T10:00:10Z', 'method' => 'facebook_c_user_cookie'],
        );
        self::assertFalse($merged['present']);
        self::assertSame('absent', $merged['status']);
    }

    public function test_c_client_recheck_true_after_admin_false(): void
    {
        $afterAdmin = SessionSignalState::mergeLastWriteWins(
            ['present' => true, 'checked_at' => '2026-10-27T10:00:00Z'],
            ['present' => false, 'checked_at' => '2026-10-27T10:00:10Z'],
        );
        self::assertFalse($afterAdmin['present']);

        $afterRecheck = SessionSignalState::mergeLastWriteWins(
            $afterAdmin,
            ['present' => true, 'status' => 'present', 'checked_at' => '2026-10-27T10:00:20Z', 'method' => 'facebook_c_user_cookie'],
        );
        self::assertTrue($afterRecheck['present']);
    }

    public function test_d_restart_keeps_newer_side_by_checked_at(): void
    {
        // Local persisted signal from before restart vs server snapshot.
        $local = ['present' => true, 'checked_at' => '2026-10-27T09:00:00Z'];
        $server = ['present' => false, 'checked_at' => '2026-10-27T09:30:00Z'];
        $applied = SessionSignalState::mergeLastWriteWins($local, $server);
        self::assertFalse($applied['present']);

        $localNewer = ['present' => true, 'checked_at' => '2026-10-27T10:00:00Z'];
        $serverOlder = ['present' => false, 'checked_at' => '2026-10-27T09:30:00Z'];
        self::assertTrue(SessionSignalState::mergeLastWriteWins($serverOlder, $localNewer)['present']);
    }

    public function test_e_two_browsers_do_not_overwrite_each_other(): void
    {
        $browserA = SessionSignalState::normalize([
            'present' => true,
            'checked_at' => '2026-10-27T10:00:00Z',
            'method' => 'facebook_c_user_cookie',
        ]);
        $browserB = SessionSignalState::normalize([
            'present' => false,
            'checked_at' => '2026-10-27T10:00:01Z',
            'method' => 'facebook_c_user_cookie',
        ]);

        // Signals are per-browser maps keyed externally; merging A must not mutate B.
        $browserAAfter = SessionSignalState::mergeLastWriteWins($browserA, [
            'present' => true,
            'checked_at' => '2026-10-27T10:00:05Z',
        ]);
        self::assertTrue($browserAAfter['present']);
        self::assertFalse($browserB['present']);
        self::assertNotSame($browserAAfter['checked_at'], $browserB['checked_at']);
    }

    public function test_f_signal_boolean_does_not_require_session_state_or_ack(): void
    {
        // Sync path only cares about present + checked_at — no session_state / ack fields.
        $signal = SessionSignalState::normalize([
            'status' => 'present',
            'checked_at' => '2026-10-27T11:00:00Z',
            'method' => 'wordpress_auth_cookie',
        ]);
        self::assertTrue($signal['present']);
        self::assertArrayNotHasKey('session_state', $signal);
        self::assertArrayNotHasKey('ack', $signal);
        self::assertArrayNotHasKey('verified', $signal);

        $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Registry/SessionSyncService.php');
        self::assertStringContainsString('SessionSignalState::mergeLastWriteWins', $src);
        self::assertStringContainsString('Independent of session_state', $src);
        self::assertStringContainsString('listSignalsForAgent', $src);
        self::assertStringContainsString('setSessionSignalPresent', $src);

        // session_state preservation for needs_login must remain separate from signal merge.
        self::assertStringContainsString("=== 'needs_login' ? 'needs_login' : 'candidate'", $src);
    }

    public function test_status_present_alias_and_explicit_present_bool(): void
    {
        self::assertTrue(SessionSignalState::isPresent(['status' => 'present']));
        self::assertFalse(SessionSignalState::isPresent(['status' => 'absent']));
        self::assertTrue(SessionSignalState::isPresent(['present' => true, 'status' => 'absent']));
        self::assertFalse(SessionSignalState::isPresent(['present' => false, 'status' => 'present']));
    }
}
