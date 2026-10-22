<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev22-source-contract.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

function dev22SourceExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function qd_runtime_core_root(): string
{
    $root = $GLOBALS['DEV22_TEST_CORE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') throw new RuntimeException('The isolated test core root is unavailable.');
    return $root;
}

function dev22SourceCleanup(string $root): void
{
    if (!file_exists($root) && !is_link($root)) return;
    $base = realpath(sys_get_temp_dir());
    $real = realpath($root);
    if (!is_string($base) || !is_string($real) || is_link($root)
        || strcasecmp(dirname($real), $base) !== 0
        || !str_starts_with(basename($real), 'qd-correspondence-dev22-')) {
        throw new RuntimeException('Unsafe dev22 fixture cleanup path.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isLink() ? !@unlink($entry->getPathname())
            : ($entry->isDir() ? !@rmdir($entry->getPathname()) : !@unlink($entry->getPathname()))) {
            throw new RuntimeException('Could not clean the dev22 fixture.');
        }
    }
    if (!@rmdir($real)) throw new RuntimeException('Could not clean the dev22 fixture.');
}

$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
dev22SourceExpect(is_string($ownerReal) && is_dir($ownerReal), 'Pass the immutable dev22 owner server source directory.');
dev22SourceExpect(is_string($adapterReal) && is_file($adapterReal), 'The candidate Department adapter is unavailable.');
$manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
dev22SourceExpect(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.22', 'Owner root is not the exact dev22 source.');

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev22-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$stagedOwnerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
if (!@mkdir($stagedOwnerRoot, 0700, true)) throw new RuntimeException('Could not create the isolated dev22 source stage.');
$GLOBALS['DEV22_TEST_CORE_ROOT'] = $coreRoot;

try {
    require $adapterReal;
    $expectedHashes = [
        'manifest.json' => '5f097681f29917d6e00614fc62cbd71e8e98d414eec240dce5d9348ef2e6bbc5',
        'lib/runtime.php' => 'dd12d1389c0547b71eaf4d4e5be242b8aed2a45763a95626ada83434cf014e12',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '0c656dd2db24a13cca49261d0e57d3c0f5da0e3a02a54c2843140d737aad9f6c',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '2361f65178d08649e1056a79fc8abd80fd7983e5eda6e7fb18aa5851bc52b568',
        'lib/offscreen_host.php' => '3b5fbb791b93bf9b719da8bbeeee7313be3699375fd2eb04aa050f8c3242a1f1',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ];
    $adapterHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-dev.22'] ?? null;
    dev22SourceExpect(is_array($adapterHashes) && $adapterHashes === $expectedHashes, 'The adapter dev22 owner pins differ from the frozen closure.');
    dev22SourceExpect(($adapterHashes['manifest.json'] ?? null) === '5f097681f29917d6e00614fc62cbd71e8e98d414eec240dce5d9348ef2e6bbc5', 'The dev22 manifest pin differs.');
    $sourceFiles = qd_correspondence_source_files($ownerReal);
    dev22SourceExpect(array_keys($sourceFiles) === array_keys($expectedHashes), 'The dev22 source closure is incomplete or overbroad.');
    dev22SourceExpect(qd_correspondence_manifest_ready($ownerReal), 'The exact dev22 owner closure did not pass the adapter manifest check.');
    $identity = qd_correspondence_current_source_identity($ownerReal);
    dev22SourceExpect(is_array($identity) && ($identity['version'] ?? null) === '0.1.0-dev.22', 'The exact dev22 owner source identity was not recognized.');

    foreach ($sourceFiles as $relative => $source) {
        $sourceReal = realpath($source);
        dev22SourceExpect(is_string($sourceReal) && !is_link($source) && str_starts_with($sourceReal, $ownerReal . DIRECTORY_SEPARATOR), 'Owner closure path escaped its exact source root: ' . $relative);
        $target = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0700, true)) throw new RuntimeException('Could not create a staged owner directory.');
        if (!@copy($sourceReal, $target)) throw new RuntimeException('Could not stage owner file: ' . $relative);
    }
    dev22SourceExpect(qd_correspondence_runtime_ready(), 'Runtime readiness did not accept the exact staged dev22 owner closure.');

    $driftPath = $stagedOwnerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'offscreen.php';
    $originalBytes = @file_get_contents($driftPath);
    dev22SourceExpect(is_string($originalBytes), 'Could not read the staged dev22 drift fixture.');
    if (@file_put_contents($driftPath, $originalBytes . ' ', LOCK_EX) === false) throw new RuntimeException('Could not mutate the staged dev22 drift fixture.');
    dev22SourceExpect(!qd_correspondence_runtime_ready(), 'Runtime readiness accepted drift in the pinned dev22 closure.');
    if (@file_put_contents($driftPath, $originalBytes, LOCK_EX) !== strlen($originalBytes)) throw new RuntimeException('Could not restore the staged dev22 drift fixture.');
    dev22SourceExpect(qd_correspondence_runtime_ready(), 'The exact dev22 source did not recover after the drift fixture was restored.');

    foreach (['0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22'] as $version) {
        dev22SourceExpect(qd_correspondence_version_uses_restore_fence($version), 'The restore fence is missing for pinned ' . $version . '.');
    }
    dev22SourceExpect(!qd_correspondence_version_uses_restore_fence('0.1.0-dev.19')
        && !qd_correspondence_version_uses_restore_fence('0.1.0-dev.23'), 'The adapter accepted an unsupported restore-fence version.');
    foreach (['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22'] as $version) {
        dev22SourceExpect(qd_correspondence_version_supports_signed_unknown($version), 'Signed-unknown support is missing for pinned ' . $version . '.');
    }
    dev22SourceExpect(!qd_correspondence_version_supports_signed_unknown('0.1.0-dev.19')
        && !qd_correspondence_version_supports_signed_unknown('0.1.0-dev.23'), 'The adapter accepted an unsupported signed-unknown version.');
    foreach (['0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22'] as $version) {
        dev22SourceExpect(qd_correspondence_version_supports_operator_close($version), 'Operator-close support is missing for pinned ' . $version . '.');
    }
    foreach (['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.19', '0.1.0-dev.23'] as $version) {
        dev22SourceExpect(!qd_correspondence_version_supports_operator_close($version), 'The adapter accepted operator-close support for unsupported ' . $version . '.');
    }
    $unsupportedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'unsupported';
    if (!@mkdir($unsupportedRoot, 0700, true)
        || @file_put_contents($unsupportedRoot . DIRECTORY_SEPARATOR . 'manifest.json', '{"name":"correspondence","version":"0.1.0-dev.23"}', LOCK_EX) === false) {
        throw new RuntimeException('Could not create the unsupported-version fixture.');
    }
    dev22SourceExpect(qd_correspondence_source_files($unsupportedRoot) === []
        && !qd_correspondence_manifest_ready($unsupportedRoot), 'An unpinned dev23 source must remain unavailable.');
    fwrite(STDOUT, "PASS: exact dev22 closure, source drift refusal, pinned capability selection, and dev23 refusal\n");
} finally {
    unset($GLOBALS['DEV22_TEST_CORE_ROOT']);
    dev22SourceCleanup($tempRoot);
}
