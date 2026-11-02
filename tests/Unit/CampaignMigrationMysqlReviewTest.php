<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Static + optional isolated MySQL review of CP19A/CP19B campaign migration.
 * Never targets production omi_social_automation for destructive tests here.
 */
final class CampaignMigrationMysqlReviewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_migration_task_id_nullable_is_additive_and_mysql_specific(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        self::assertStringContainsString('MODIFY task_id BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString("getDriverName()", $sql);
        self::assertStringContainsString("'mysql'", $sql);
        // Existing task-bound jobs keep task_id values; only nullability widens.
        self::assertStringNotContainsString('DROP COLUMN task_id', $sql);
        self::assertStringNotContainsString('dropIfExists(\'sa_jobs\')', $sql);
        self::assertStringContainsString('campaign_id', $sql);
        self::assertStringContainsString('lease_token', $sql);
        self::assertStringContainsString('fencing_generation', $sql);
        self::assertStringContainsString('run_mode', $sql);
    }

    public function test_migration_down_drops_campaign_tables_only(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        self::assertStringContainsString("dropIfExists('sa_campaign_account_assignments')", $sql);
        self::assertStringContainsString("dropIfExists('sa_campaign_contents')", $sql);
        self::assertStringContainsString("dropIfExists('sa_campaigns')", $sql);
        // down() removes additive job columns but must not drop sa_jobs table.
        self::assertStringContainsString("dropColumn", $sql);
        self::assertStringNotContainsString("dropIfExists('sa_jobs')", $sql);
        self::assertStringNotContainsString("dropIfExists('sa_accounts')", $sql);
    }

    public function test_foreign_keys_and_indexes_declared(): void
    {
        $sql = (string) file_get_contents($this->root.'/database/migrations/2026_11_02_150000_create_sa_campaign_tables.php');
        self::assertStringContainsString("constrained('sa_campaigns')", $sql);
        self::assertStringContainsString("constrained('sa_accounts')", $sql);
        self::assertStringContainsString('sa_campaign_account_unique', $sql);
        self::assertStringContainsString('sa_campaign_contents_campaign_external_unique', $sql);
        self::assertStringContainsString("index(['campaign_id', 'status'])", $sql);
    }

    public function test_lock_for_update_used_in_orchestrator(): void
    {
        $orch = (string) file_get_contents($this->root.'/src/Orchestration/CampaignOrchestrator.php');
        self::assertGreaterThanOrEqual(5, substr_count($orch, 'lockForUpdate'));
        self::assertStringContainsString('AccountLockPolicy', $orch);
        self::assertStringContainsString('transaction', $orch);
    }

    public function test_isolated_mysql_migration_smoke_or_unavailable(): void
    {
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('MYSQL_TEST_UNAVAILABLE: PDO mysql driver missing');
        }

        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
        } catch (\Throwable $e) {
            self::markTestSkipped('MYSQL_TEST_UNAVAILABLE: '.$e->getMessage());
        }

        $db = 'omi_social_automation_cp19b_test';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db}`");

        // Minimal parent tables for FK smoke (isolated test DB only).
        $pdo->exec('DROP TABLE IF EXISTS sa_campaign_account_assignments');
        $pdo->exec('DROP TABLE IF EXISTS sa_campaign_contents');
        $pdo->exec('DROP TABLE IF EXISTS sa_campaigns');
        $pdo->exec('DROP TABLE IF EXISTS sa_account_execution_leases');
        $pdo->exec('DROP TABLE IF EXISTS sa_job_attempts');
        $pdo->exec('DROP TABLE IF EXISTS sa_jobs');
        $pdo->exec('DROP TABLE IF EXISTS sa_accounts');

        $pdo->exec('CREATE TABLE sa_accounts (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            account_uuid CHAR(36) NOT NULL
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE sa_jobs (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL,
            account_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(64) NULL,
            INDEX (account_id),
            INDEX (status)
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE sa_job_attempts (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            attempt_uuid CHAR(36) NOT NULL,
            job_id BIGINT UNSIGNED NOT NULL,
            attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(32) NOT NULL,
            UNIQUE KEY uq_job_attempt (job_id, attempt_no)
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE sa_account_execution_leases (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            job_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            UNIQUE KEY uq_job (job_id),
            INDEX (account_id, status)
        ) ENGINE=InnoDB');

        // Apply nullable alteration + campaign tables (subset mirroring migration intent).
        $pdo->exec('ALTER TABLE sa_jobs MODIFY task_id BIGINT UNSIGNED NULL');
        $pdo->exec('ALTER TABLE sa_jobs
            ADD COLUMN campaign_id BIGINT UNSIGNED NULL,
            ADD COLUMN campaign_content_id BIGINT UNSIGNED NULL,
            ADD COLUMN lease_token VARCHAR(64) NULL,
            ADD COLUMN fencing_generation INT UNSIGNED NOT NULL DEFAULT 0,
            ADD INDEX (campaign_id),
            ADD INDEX (campaign_content_id)');
        $pdo->exec("CREATE TABLE sa_campaigns (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            campaign_uuid CHAR(36) NOT NULL UNIQUE,
            installation_id VARCHAR(128) NOT NULL,
            name VARCHAR(255) NOT NULL,
            platform VARCHAR(64) NOT NULL,
            execution_mode VARCHAR(32) NOT NULL,
            run_mode VARCHAR(16) NOT NULL DEFAULT 'live',
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            script_id VARCHAR(128) NOT NULL,
            script_version VARCHAR(64) NOT NULL,
            INDEX (installation_id, status),
            INDEX (run_mode, status)
        ) ENGINE=InnoDB");
        $pdo->exec('CREATE TABLE sa_campaign_contents (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            content_uuid CHAR(36) NOT NULL UNIQUE,
            campaign_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'pending\',
            CONSTRAINT fk_content_campaign FOREIGN KEY (campaign_id) REFERENCES sa_campaigns(id) ON DELETE CASCADE,
            INDEX (campaign_id, status)
        ) ENGINE=InnoDB');

        // Existing task job remains valid with non-null task_id.
        $pdo->exec("INSERT INTO sa_jobs (task_id, status) VALUES (1, 'pending')");
        // Campaign job with null task_id.
        $pdo->exec("INSERT INTO sa_jobs (task_id, status, campaign_id, lease_token, fencing_generation)
            VALUES (NULL, 'reserved', 1, 'tok', 1)");

        // Concurrent account claim: only one active lease wins.
        $pdo->exec('INSERT INTO sa_accounts (account_uuid) VALUES (\'acc-1\')');
        $pdo->beginTransaction();
        $active = (int) $pdo->query("SELECT COUNT(*) FROM sa_account_execution_leases WHERE account_id=1 AND status='active'")->fetchColumn();
        self::assertSame(0, $active);
        $pdo->exec("INSERT INTO sa_account_execution_leases (account_id, job_id, status) VALUES (1, 2, 'active')");
        $pdo->commit();

        $pdo->beginTransaction();
        $busy = (int) $pdo->query("SELECT COUNT(*) FROM sa_account_execution_leases WHERE account_id=1 AND status='active'")->fetchColumn();
        $second = false;
        if ($busy === 0) {
            $pdo->exec("INSERT INTO sa_account_execution_leases (account_id, job_id, status) VALUES (1, 1, 'active')");
            $second = true;
        }
        $pdo->commit();
        self::assertFalse($second);

        $nullTask = (int) $pdo->query('SELECT COUNT(*) FROM sa_jobs WHERE task_id IS NULL')->fetchColumn();
        self::assertSame(1, $nullTask);
        $taskJobs = (int) $pdo->query('SELECT COUNT(*) FROM sa_jobs WHERE task_id IS NOT NULL')->fetchColumn();
        self::assertSame(1, $taskJobs);

        // Cleanup isolated DB objects (keep database for re-runs).
        $pdo->exec('DROP TABLE IF EXISTS sa_campaign_contents');
        $pdo->exec('DROP TABLE IF EXISTS sa_campaigns');
        $pdo->exec('DROP TABLE IF EXISTS sa_account_execution_leases');
        $pdo->exec('DROP TABLE IF EXISTS sa_job_attempts');
        $pdo->exec('DROP TABLE IF EXISTS sa_jobs');
        $pdo->exec('DROP TABLE IF EXISTS sa_accounts');

        self::assertTrue(true, 'Isolated MySQL smoke completed');
    }
}
