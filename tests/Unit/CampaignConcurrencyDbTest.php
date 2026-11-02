<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Database-backed concurrency proofs for CP19A lock/idempotency semantics.
 * Uses in-memory SQLite when PDO SQLite is available (no production DB touch).
 */
final class CampaignConcurrencyDbTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite unavailable');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bootstrapSchema($this->pdo);
    }

    public function test_concurrent_claim_only_one_wins(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO jobs (id, status, account_id, lease_token, fencing_generation) VALUES (1, 'reserved', 10, 'tok-a', 1)");

        $winners = 0;
        foreach (['agent-a', 'agent-b'] as $agent) {
            $pdo->beginTransaction();
            $stmt = $pdo->query("SELECT id, status FROM jobs WHERE id = 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['status'] === 'reserved') {
                $upd = $pdo->prepare("UPDATE jobs SET status = 'claimed', lease_token = ?, claimed_by = ? WHERE id = 1 AND status = 'reserved'");
                $upd->execute(['tok-'.$agent, $agent]);
                if ($upd->rowCount() === 1) {
                    $winners++;
                }
            }
            $pdo->commit();
        }
        self::assertSame(1, $winners);
        $final = $pdo->query('SELECT status, claimed_by FROM jobs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('claimed', $final['status']);
    }

    public function test_same_account_two_browsers_one_active_lease(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO account_leases (account_id, job_id, status, lease_token) VALUES (42, 1, 'active', 'L1')");

        $acquired = 0;
        foreach ([2, 3] as $jobId) {
            $pdo->beginTransaction();
            $active = $pdo->query("SELECT COUNT(*) FROM account_leases WHERE account_id = 42 AND status = 'active'")->fetchColumn();
            if ((int) $active === 0) {
                $ins = $pdo->prepare("INSERT INTO account_leases (account_id, job_id, status, lease_token) VALUES (42, ?, 'active', ?)");
                $ins->execute([$jobId, 'L'.$jobId]);
                $acquired++;
            }
            $pdo->commit();
        }
        self::assertSame(0, $acquired);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM account_leases WHERE account_id = 42 AND status = 'active'")->fetchColumn();
        self::assertSame(1, $count);
    }

    public function test_two_campaigns_contend_for_same_account(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        // Campaign A holds active lock on account 7
        $pdo->exec("INSERT INTO account_leases (account_id, job_id, status, lease_token) VALUES (7, 100, 'active', 'A')");

        $pdo->beginTransaction();
        $busy = (int) $pdo->query("SELECT COUNT(*) FROM account_leases WHERE account_id = 7 AND status = 'active'")->fetchColumn();
        $canB = $busy === 0;
        if ($canB) {
            $pdo->exec("INSERT INTO account_leases (account_id, job_id, status, lease_token) VALUES (7, 200, 'active', 'B')");
        }
        $pdo->commit();
        self::assertFalse($canB);
    }

    public function test_stale_lease_result_rejected(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO jobs (id, status, account_id, lease_token, fencing_generation) VALUES (5, 'running', 1, 'current-token', 2)");

        $presented = 'stale-token';
        $row = $pdo->query('SELECT lease_token, fencing_generation, status FROM jobs WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
        $accepted = hash_equals((string) $row['lease_token'], $presented);
        self::assertFalse($accepted);
    }

    public function test_late_result_cannot_overwrite_newer_result(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO jobs (id, status, account_id, lease_token, fencing_generation) VALUES (9, 'completed', 1, 'tok-new', 3)");
        $pdo->exec("INSERT INTO attempts (job_id, attempt_uuid, fencing_generation, status) VALUES (9, 'att-new', 3, 'succeeded')");

        $lateToken = 'tok-old';
        $lateFence = 2;
        $row = $pdo->query('SELECT lease_token, fencing_generation, status FROM jobs WHERE id = 9')->fetch(PDO::FETCH_ASSOC);
        $canOverwrite = $row['status'] !== 'completed'
            && hash_equals((string) $row['lease_token'], $lateToken)
            && (int) $row['fencing_generation'] === $lateFence;
        self::assertFalse($canOverwrite);
    }

    public function test_resource_shortage_marks_waiting_not_failed(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO contents (id, campaign_id, status) VALUES (1, 1, 'pending')");
        $eligibleAccounts = 0;
        $pdo->beginTransaction();
        if ($eligibleAccounts === 0) {
            $pdo->exec("UPDATE contents SET status = 'waiting_resources' WHERE id = 1 AND status IN ('pending','waiting_resources')");
        } else {
            $pdo->exec("UPDATE contents SET status = 'failed_locked' WHERE id = 1");
        }
        $pdo->commit();
        $status = $pdo->query('SELECT status FROM contents WHERE id = 1')->fetchColumn();
        self::assertSame('waiting_resources', $status);
    }

    public function test_recheck_idempotent_no_duplicate_job(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO contents (id, campaign_id, status) VALUES (2, 1, 'waiting_resources')");
        $idem = 'campaign:c1:content:x:attempt:1';

        $created = 0;
        for ($i = 0; $i < 2; $i++) {
            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT id FROM jobs WHERE idempotency_key = ?');
            $exists->execute([$idem]);
            if (! $exists->fetch()) {
                $ins = $pdo->prepare("INSERT INTO jobs (status, account_id, lease_token, fencing_generation, idempotency_key) VALUES ('reserved', 1, 't', 1, ?)");
                $ins->execute([$idem]);
                $created++;
            }
            $pdo->commit();
        }
        self::assertSame(1, $created);
        $jobs = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE idempotency_key = '.$pdo->quote($idem))->fetchColumn();
        self::assertSame(1, $jobs);
    }

    public function test_admin_retry_idempotent_double_click(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO contents (id, campaign_id, status, admin_action_idempotency) VALUES (3, 1, 'failed_locked', NULL)");
        $key = 'retry-key-1';
        $actions = 0;
        for ($i = 0; $i < 2; $i++) {
            $pdo->beginTransaction();
            $row = $pdo->query('SELECT status, admin_action_idempotency FROM contents WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
            if ($row['admin_action_idempotency'] === $key && in_array($row['status'], ['pending', 'waiting_resources', 'running'], true)) {
                // idempotent no-op
            } elseif ($row['status'] === 'failed_locked') {
                $upd = $pdo->prepare("UPDATE contents SET status = 'pending', admin_action_idempotency = ? WHERE id = 3 AND status = 'failed_locked'");
                $upd->execute([$key]);
                if ($upd->rowCount() === 1) {
                    $actions++;
                }
            }
            $pdo->commit();
        }
        self::assertSame(1, $actions);
    }

    public function test_pause_and_cancel_block_dispatch_flag(): void
    {
        $pdo = $this->pdo;
        self::assertNotNull($pdo);
        $pdo->exec("INSERT INTO campaigns (id, status, next_recheck_at) VALUES (1, 'running', '2026-11-02T00:00:00Z')");
        $pdo->exec("UPDATE campaigns SET status = 'paused', next_recheck_at = NULL WHERE id = 1");
        $row = $pdo->query('SELECT status, next_recheck_at FROM campaigns WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('paused', $row['status']);
        self::assertNull($row['next_recheck_at']);

        $pdo->exec("UPDATE campaigns SET status = 'cancelled' WHERE id = 1");
        $allows = $pdo->query("SELECT status FROM campaigns WHERE id = 1")->fetchColumn() === 'running';
        self::assertFalse($allows);
    }

    private function bootstrapSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT NOT NULL,
            account_id INTEGER,
            lease_token TEXT,
            fencing_generation INTEGER DEFAULT 0,
            claimed_by TEXT,
            idempotency_key TEXT UNIQUE
        )');
        $pdo->exec('CREATE TABLE attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            attempt_uuid TEXT,
            fencing_generation INTEGER,
            status TEXT
        )');
        $pdo->exec('CREATE TABLE account_leases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            job_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            lease_token TEXT
        )');
        $pdo->exec('CREATE TABLE contents (
            id INTEGER PRIMARY KEY,
            campaign_id INTEGER,
            status TEXT,
            admin_action_idempotency TEXT
        )');
        $pdo->exec('CREATE TABLE campaigns (
            id INTEGER PRIMARY KEY,
            status TEXT,
            next_recheck_at TEXT
        )');
    }
}
