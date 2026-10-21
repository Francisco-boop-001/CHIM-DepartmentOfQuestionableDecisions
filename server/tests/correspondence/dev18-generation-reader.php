<?php
declare(strict_types=1);

require_once __DIR__ . '/../../adapters/runtime_support.php';

function dev18_generation_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-generation-' . bin2hex(random_bytes(8));
$coreRoot = $fixture . DIRECTORY_SEPARATOR . 'core';
$runtimeRoot = $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime';
if (!mkdir($runtimeRoot, 0700, true) || !mkdir($coreRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)) {
    throw new RuntimeException('Could not create the isolated generation fixture.');
}
if (file_put_contents($coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n") === false) {
    throw new RuntimeException('Could not create the inert core-root probe marker.');
}
$generationPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'generation';
putenv('QD_CHIM_ROOT=' . $coreRoot);

try {
    dev18_generation_expect(qd_runtime_correspondence_read_generation() === '', 'Missing generation must represent the initial pre-switch state.');
    $generation = '0123456789abcdef0123456789abcdef';
    if (file_put_contents($generationPath, $generation . "\n") === false) throw new RuntimeException('Could not write the isolated generation fixture.');
    dev18_generation_expect(qd_runtime_correspondence_read_generation() === $generation, 'The current native generation must be read after initial absence.');
    if (file_put_contents($generationPath, "malformed\n") === false) throw new RuntimeException('Could not corrupt the isolated generation fixture.');
    $rejected = false;
    try {
        qd_runtime_correspondence_read_generation();
    } catch (RuntimeException) {
        $rejected = true;
    }
    dev18_generation_expect($rejected, 'A present malformed generation must fail closed.');
    if (!unlink($generationPath)) throw new RuntimeException('Could not remove the isolated generation fixture.');
    dev18_generation_expect(qd_runtime_correspondence_read_generation() === '', 'The missing generation must be observed after a prior successful read.');
} finally {
    putenv('QD_CHIM_ROOT');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}

fwrite(STDOUT, "PASS: initial absent generation, fresh rereads, and malformed generation refusal\n");
