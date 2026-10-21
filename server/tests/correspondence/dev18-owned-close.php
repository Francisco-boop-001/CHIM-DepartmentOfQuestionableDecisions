<?php
declare(strict_types=1);

require_once __DIR__ . '/../../adapters/runtime_support.php';
require_once __DIR__ . '/../../adapters/correspondence.php';

if (!function_exists('pg_close')) throw new RuntimeException('The focused close test requires the PHP pgsql extension.');

$notAPgConnection = new stdClass();
$primary = new RuntimeException('owner projection failure');
$observed = null;
try {
    qd_runtime_with_owned_connection(
        static fn() => $notAPgConnection,
        static fn($owned): mixed => qd_correspondence_close_owned_pg_connection($owned),
        static function ($owned) use ($primary): void {
            // Simulate a failing close callback before the outer owned cleanup runs.
            qd_correspondence_close_owned_pg_connection($owned);
            throw $primary;
        },
    );
} catch (Throwable $error) {
    $observed = $error;
}

if ($observed !== $primary) throw new RuntimeException('The close TypeError masked the operation error.');
fwrite(STDOUT, "PASS: a close TypeError does not mask the original operation failure\n");
