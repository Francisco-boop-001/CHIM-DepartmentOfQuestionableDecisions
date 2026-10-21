<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/adapters/runtime_support.php';

function dev18_lease_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-lease-' . bin2hex(random_bytes(8));
$lockDirectory = $fixture . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime';
if (!mkdir($lockDirectory, 0700, true)) throw new RuntimeException('Could not create the isolated lease fixture.');
$lockPath = $lockDirectory . DIRECTORY_SEPARATOR . 'work.lock';
$lease = fopen($lockPath, 'c+b');
if ($lease === false) throw new RuntimeException('Could not open the isolated lease fixture.');
$GLOBALS['qd_runtime_owned_profile_lease'] = $lease;

try {
    dev18_lease_expect(qd_runtime_correspondence_lease_resource($fixture . DIRECTORY_SEPARATOR . 'core') === $lease, 'The current work.lock handle should be accepted.');

    if (PHP_OS_FAMILY !== 'Windows') {
        $replacement = $lockPath . '.replacement';
        if (file_put_contents($replacement, "replacement\n", LOCK_EX) === false || !rename($replacement, $lockPath)) {
            throw new RuntimeException('Could not replace the isolated work.lock path.');
        }
        $rejected = false;
        try {
            qd_runtime_correspondence_lease_resource($fixture . DIRECTORY_SEPARATOR . 'core');
        } catch (RuntimeException) {
            $rejected = true;
        }
        dev18_lease_expect($rejected, 'A borrowed handle to a replaced work.lock inode must be rejected.');
    }
} finally {
    unset($GLOBALS['qd_runtime_owned_profile_lease']);
    fclose($lease);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}

fwrite(STDOUT, PHP_OS_FAMILY === 'Windows' ? "PASS accepted current lease; inode replacement skipped on Windows\n" : "PASS replaced work.lock inode rejected\n");
