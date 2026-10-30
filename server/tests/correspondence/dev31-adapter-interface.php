<?php
declare(strict_types=1);

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--owner-version' || str_starts_with($argument, '--owner-version=')) {
        fwrite(STDERR, "dev31-adapter-interface fixes --owner-version to 0.1.0-dev.31\n");
        exit(2);
    }
}

$argv[] = '--owner-version';
$argv[] = '0.1.0-dev.31';
$argc = count($argv);
require __DIR__ . DIRECTORY_SEPARATOR . 'dev30-adapter-interface.php';