<?php
declare(strict_types=1);

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--owner-version' || str_starts_with($argument, '--owner-version=')) {
        fwrite(STDERR, "dev29-source-contract fixes --owner-version to 0.1.0-dev.29\n");
        exit(2);
    }
}

$argv[] = '--owner-version';
$argv[] = '0.1.0-dev.29';
$argc = count($argv);
require __DIR__ . DIRECTORY_SEPARATOR . 'dev23-source-contract.php';
