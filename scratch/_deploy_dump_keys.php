<?php

declare(strict_types=1);

/**
 * Dump sa_client_api_keys metadata+rows (no plaintext raw keys beyond encrypted_key column as stored)
 * and full omi_social_automation mysqldump gzip.
 */

$outDir = $argv[1] ?? '';
if ($outDir === '' || ! is_dir($outDir)) {
    fwrite(STDERR, "usage: dump.php <outdir>\n");
    exit(1);
}

require '/www/wwwroot/seo-ops-client/vendor/autoload.php';
$app = require '/www/wwwroot/seo-ops-client/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = config('database.connections.omi_social_automation');
$host = (string) ($conn['host'] ?? '127.0.0.1');
$port = (string) ($conn['port'] ?? '3306');
$db = (string) ($conn['database'] ?? 'omi_social_automation');
$user = (string) ($conn['username'] ?? 'root');
$pass = (string) ($conn['password'] ?? '');

$stamp = gmdate('Ymd\THis\Z');
$metaPath = $outDir.'/keys_meta_'.$stamp.'.json';
$sqlPath = $outDir.'/sa_client_api_keys_'.$stamp.'.sql';
$gzPath = $outDir.'/omi_social_automation_'.$stamp.'.sql.gz';

$rows = DB::connection('omi_social_automation')
    ->table('sa_client_api_keys')
    ->orderBy('id')
    ->get(['id', 'name', 'key_prefix', 'created_by', 'revoked_at', 'last_used_at', 'created_at', 'updated_at', 'installation_id']);

$meta = [
    'ts' => gmdate('c'),
    'database' => $db,
    'count' => count($rows),
    'active' => $rows->whereNull('revoked_at')->count(),
    'rows' => $rows,
];
file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

// Table-only dump via mysqldump
$env = [
    'MYSQL_PWD' => $pass,
];
$cmdTable = sprintf(
    'mysqldump -h%s -P%s -u%s --single-transaction --routines=false --triggers=false %s sa_client_api_keys > %s',
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($db),
    escapeshellarg($sqlPath)
);
// escapeshellarg adds quotes unsuitable inside sprintf for all - build carefully
$cmdTable = 'mysqldump -h'.escapeshellarg($host)
    .' -P'.escapeshellarg($port)
    .' -u'.escapeshellarg($user)
    .' --single-transaction --skip-routines --skip-triggers '
    .escapeshellarg($db).' sa_client_api_keys > '.escapeshellarg($sqlPath);

$cmdFull = 'mysqldump -h'.escapeshellarg($host)
    .' -P'.escapeshellarg($port)
    .' -u'.escapeshellarg($user)
    .' --single-transaction --routines --triggers '
    .escapeshellarg($db).' | gzip -c > '.escapeshellarg($gzPath);

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$run = static function (string $command, array $env) use ($descriptors): array {
    $proc = proc_open($command, $descriptors, $pipes, null, $env + $_ENV);
    if (! is_resource($proc)) {
        return [1, '', 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [$code, $stdout, $stderr];
};

[$c1, $o1, $e1] = $run($cmdTable, $env);
if ($c1 !== 0 || ! is_file($sqlPath) || filesize($sqlPath) < 50) {
    fwrite(STDERR, "TABLE_DUMP_FAIL code={$c1} err={$e1}\n");
    exit(2);
}

[$c2, $o2, $e2] = $run($cmdFull, $env);
if ($c2 !== 0 || ! is_file($gzPath) || filesize($gzPath) < 50) {
    fwrite(STDERR, "FULL_DUMP_FAIL code={$c2} err={$e2}\n");
    exit(3);
}

echo "META={$metaPath}\n";
echo "SQL={$sqlPath}\n";
echo "GZ={$gzPath}\n";
echo 'SQL_BYTES='.filesize($sqlPath).PHP_EOL;
echo 'GZ_BYTES='.filesize($gzPath).PHP_EOL;
echo "DUMP_OK=yes\n";
