<?php

declare(strict_types=1);

/**
 * Standalone regression check (no host PHPUnit required).
 * Ensures publish transaction closure captures $input — root cause of
 * production HTTP 503 package_registry_write_failed under Laravel's
 * warning→ErrorException handler.
 */

$root = dirname(__DIR__);
$source = (string) file_get_contents($root.'/src/PackageRegistry/PackageRegistryService.php');
$failures = [];

if (! preg_match(
    '/transaction\(function \(\) use \(\$data, \$userId, \$syncSource, \$input\): PackagePublishResult/',
    $source
)) {
    $failures[] = 'publish transaction closure must capture $input';
}

if (! str_contains($source, 'linkActionTagAndRequest($package, $existing, $data, $input)')) {
    $failures[] = 'idempotent path must pass $input to linkActionTagAndRequest';
}

if (! str_contains($source, 'linkActionTagAndRequest($package, $release, $data, $input)')) {
    $failures[] = 'create path must pass $input to linkActionTagAndRequest';
}

if (! str_contains($source, 'report($e)')) {
    $failures[] = 'write failure must report() the Throwable for ops logs';
}

// Simulate Laravel-style error handler converting undefined-variable Warning → Exception.
set_error_handler(static function (int $severity, string $message): bool {
    throw new ErrorException($message, 0, $severity);
});

try {
    (static function (): void {
        // Intentionally undefined — mirrors pre-fix closure body using $input without use().
        $value = $input;
        unset($value);
    })();
    $failures[] = 'expected ErrorException for undefined $input was not thrown';
} catch (ErrorException $e) {
    if (! str_contains($e->getMessage(), 'Undefined variable')) {
        $failures[] = 'unexpected ErrorException: '.$e->getMessage();
    }
} finally {
    restore_error_handler();
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL\n".implode("\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "PASS publish-closure-capture\n");
exit(0);
