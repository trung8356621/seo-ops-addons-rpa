<?php

declare(strict_types=1);

/**
 * Local: SHA-256 of GitHub commit blobs for key campaign-dispatch files.
 * Usage: php scratch/cp19-runtime-verify-hashes.php [commit]
 */

$commit = $argv[1] ?? 'e0979b4';
$root = dirname(__DIR__);
chdir($root);

$files = [
    'src/Domain/AgentKindPolicy.php',
    'src/Domain/EligibilitySelector.php',
    'src/Orchestration/CampaignOrchestrator.php',
    'src/Domain/CampaignResourceRecheckNudge.php',
    'src/Registry/AgentRegistryService.php',
    'src/Registry/SessionSyncService.php',
];

$out = ['commit' => null, 'files' => []];

exec('git rev-parse '.$commit, $revLines, $revCode);
$out['commit'] = $revCode === 0 ? trim($revLines[0] ?? '') : null;

foreach ($files as $f) {
    $spec = escapeshellarg($commit.':'.$f);
    // git cat-file -p for exact blob bytes
    $cmd = 'git cat-file -e '.$spec.' 2>NUL';
    exec($cmd, $ignore, $existsCode);
    if ($existsCode !== 0) {
        // Windows NUL
        exec('git cat-file -e '.$spec.' 2>$null', $ignore2, $existsCode);
    }
    // Use proc_open for binary-safe blob
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('git cat-file -p '.$commit.':'.$f, $descriptors, $pipes, $root);
    if (! is_resource($proc)) {
        $out['files'][$f] = ['ok' => false, 'error' => 'proc_open_failed'];
        continue;
    }
    fclose($pipes[0]);
    $blob = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || $blob === false) {
        $out['files'][$f] = ['ok' => false, 'error' => trim((string) $err) ?: 'missing'];
        continue;
    }
    $markers = [
        'client_dispatch' => str_contains($blob, 'KIND_CLIENT') && str_contains($blob, 'countsAsOnlineForDispatch'),
        'require_strong_identity' => str_contains($blob, 'require_strong_identity'),
        'resource_nudge' => str_contains($blob, 'CampaignResourceRecheckNudge') || str_contains($blob, 'nudgeForAccounts') || str_contains($blob, 'resource_nudge_at'),
        'client_online_dispatch' => str_contains($blob, 'KIND_RUNNER || $normalized === self::KIND_CLIENT')
            || str_contains($blob, "normalized === self::KIND_CLIENT"),
    ];
    $out['files'][$f] = [
        'ok' => true,
        'sha256' => hash('sha256', $blob),
        'bytes' => strlen($blob),
        'markers' => $markers,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
