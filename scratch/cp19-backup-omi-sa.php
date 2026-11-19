<?php

declare(strict_types=1);

/**
 * Phase A only: mysqldump omi_social_automation using Laravel config credentials.
 * Never prints password. No schema mutations.
 */

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connName = 'omi_social_automation';
$cfg = config("database.connections.{$connName}");
if (! is_array($cfg) || ($cfg['database'] ?? '') !== 'omi_social_automation') {
    fwrite(STDERR, "REFUSE: connection database is not omi_social_automation\n");
    exit(2);
}

$host = (string) ($cfg['host'] ?? '127.0.0.1');
$port = (string) ($cfg['port'] ?? '3306');
$db = (string) $cfg['database'];
$user = (string) ($cfg['username'] ?? '');
$pass = (string) ($cfg['password'] ?? '');

if ($db !== 'omi_social_automation' || $user === '') {
    fwrite(STDERR, "REFUSE: invalid dump target or missing username\n");
    exit(2);
}

$stamp = gmdate('Ymd\THis\Z');
$backupRoot = '/www/backup/database/omi_social_automation';
if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0700, true) && ! is_dir($backupRoot)) {
    fwrite(STDERR, "FAIL: cannot create backup root\n");
    exit(3);
}
chmod($backupRoot, 0700);

$dumpPath = $backupRoot.'/omi_social_automation_'.$stamp.'.sql';
$gzPath = $dumpPath.'.gz';
$shaPath = $gzPath.'.sha256';
$metaPath = $backupRoot.'/omi_social_automation_'.$stamp.'.meta.json';

$defaults = tempnam(sys_get_temp_dir(), 'my_');
if ($defaults === false) {
    fwrite(STDERR, "FAIL: temp defaults file\n");
    exit(3);
}
chmod($defaults, 0600);
$defaultsContent = "[client]\n"
    ."host={$host}\n"
    ."port={$port}\n"
    ."user={$user}\n"
    .'password="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $pass)."\"\n";
if (file_put_contents($defaults, $defaultsContent) === false) {
    @unlink($defaults);
    fwrite(STDERR, "FAIL: write defaults\n");
    exit(3);
}

$mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null')) ?: '/usr/bin/mysqldump';
$gzip = trim((string) shell_exec('command -v gzip 2>/dev/null')) ?: '/usr/bin/gzip';
$sha256sum = trim((string) shell_exec('command -v sha256sum 2>/dev/null')) ?: '/usr/bin/sha256sum';

// Consistent InnoDB snapshot: single-transaction + routines/triggers/events.
$cmd = escapeshellarg($mysqldump)
    .' --defaults-extra-file='.escapeshellarg($defaults)
    .' --single-transaction'
    .' --quick'
    .' --skip-lock-tables'
    .' --routines'
    .' --triggers'
    .' --events'
    .' --hex-blob'
    .' --default-character-set=utf8mb4'
    .' --set-gtid-purged=OFF'
    .' --databases '.escapeshellarg($db)
    .' > '.escapeshellarg($dumpPath)
    .' 2> '.escapeshellarg($dumpPath.'.err');

$started = microtime(true);
passthru($cmd, $dumpExit);
$elapsed = round(microtime(true) - $started, 3);

// Scrub credentials immediately.
@unlink($defaults);

$errText = is_file($dumpPath.'.err') ? trim((string) file_get_contents($dumpPath.'.err')) : '';
@unlink($dumpPath.'.err');

$result = [
    'ok' => false,
    'phase' => 'A_backup',
    'database' => $db,
    'host' => $host,
    'port' => $port,
    'dump_exit_code' => $dumpExit,
    'elapsed_sec' => $elapsed,
    'dump_path' => $dumpPath,
    'gz_path' => $gzPath,
    'sha_path' => $shaPath,
    'meta_path' => $metaPath,
    'stderr_sanitized' => $errText !== '' ? preg_replace('/password[=:].*/i', 'password=[REDACTED]', $errText) : null,
];

if ($dumpExit !== 0 || ! is_file($dumpPath) || filesize($dumpPath) < 1000) {
    $result['error'] = 'mysqldump_failed_or_too_small';
    $result['dump_bytes'] = is_file($dumpPath) ? filesize($dumpPath) : 0;
    file_put_contents($metaPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    chmod($metaPath, 0600);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(4);
}

chmod($dumpPath, 0600);
$result['dump_bytes'] = filesize($dumpPath);

// Integrity: required markers / tables in SQL text before gzip.
$sqlHead = (string) file_get_contents($dumpPath, false, null, 0, 200000);
$required = [
    'CREATE DATABASE',
    '`omi_social_automation`',
    'sa_accounts',
    'sa_jobs',
    'sa_job_attempts',
    'sa_account_execution_leases',
    'sa_tasks',
    'migrations',
];
$found = [];
foreach ($required as $needle) {
    $found[$needle] = str_contains($sqlHead, $needle) || str_contains((string) shell_exec('grep -m1 -F '.escapeshellarg($needle).' '.escapeshellarg($dumpPath).' || true'), $needle);
}
// Grep whole file for tables that may appear later
foreach (['sa_accounts', 'sa_jobs', 'sa_job_attempts', 'sa_account_execution_leases', 'migrations'] as $t) {
    $hit = trim((string) shell_exec('grep -m1 -F '.escapeshellarg('`'.$t.'`').' '.escapeshellarg($dumpPath).' || true'));
    $found[$t] = $hit !== '';
}
$result['content_checks'] = $found;
$result['content_checks_ok'] = ! in_array(false, $found, true);

// Count CREATE TABLE
$createCount = (int) trim((string) shell_exec('grep -c "^CREATE TABLE" '.escapeshellarg($dumpPath).' || true'));
$result['create_table_count'] = $createCount;

passthru(escapeshellarg($gzip).' -n '.escapeshellarg($dumpPath), $gzExit);
$result['gzip_exit_code'] = $gzExit;
if ($gzExit !== 0 || ! is_file($gzPath)) {
    $result['error'] = 'gzip_failed';
    file_put_contents($metaPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    chmod($metaPath, 0600);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(5);
}
chmod($gzPath, 0600);
$result['gz_bytes'] = filesize($gzPath);

// gzip -t integrity
passthru(escapeshellarg($gzip).' -t '.escapeshellarg($gzPath), $testExit);
$result['gzip_test_exit_code'] = $testExit;

$shaLine = trim((string) shell_exec(escapeshellarg($sha256sum).' '.escapeshellarg($gzPath)));
$result['sha256_line'] = $shaLine;
file_put_contents($shaPath, $shaLine."\n");
chmod($shaPath, 0600);
if (preg_match('/^([a-f0-9]{64})\s+/', $shaLine, $m)) {
    $result['sha256'] = $m[1];
}

// Restore instructions (do not execute)
$result['restore_instructions'] = [
    'warning' => 'DO NOT run unless approved rollback. This overwrites omi_social_automation.',
    'steps' => [
        'cd /www/backup/database/omi_social_automation',
        'sha256sum -c '.basename($shaPath),
        'gzip -t '.basename($gzPath),
        'gunzip -c '.basename($gzPath).' | mysql --defaults-extra-file=/path/to/secure.cnf',
    ],
    'note' => 'Use a defaults-extra-file with host/user/password; never paste password on CLI history.',
];

$result['ok'] = $result['content_checks_ok']
    && $dumpExit === 0
    && $gzExit === 0
    && $testExit === 0
    && isset($result['sha256'])
    && ($result['gz_bytes'] ?? 0) > 100;

file_put_contents($metaPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
chmod($metaPath, 0600);
@chmod($backupRoot, 0700);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['ok'] ? 0 : 6);
