<?php

declare(strict_types=1);

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "MYSQL_OK\n";
    $dbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dbs as $db) {
        if (str_contains((string) $db, 'omi') || str_contains((string) $db, 'social') || str_contains((string) $db, 'test')) {
            echo "DB:{$db}\n";
        }
    }
    // Isolated test DB only — never touch production schema casually.
    $pdo->exec('CREATE DATABASE IF NOT EXISTS omi_social_automation_cp19b_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "TEST_DB_READY:omi_social_automation_cp19b_test\n";
} catch (Throwable $e) {
    echo 'MYSQL_UNAVAILABLE:'.$e->getMessage()."\n";
    exit(1);
}
