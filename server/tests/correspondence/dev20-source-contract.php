<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . '/adapters/correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev20-source-contract.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

function dev20SourceExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function qd_runtime_core_root(): string
{
    $root = $GLOBALS['DEV20_TEST_CORE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') throw new RuntimeException('The isolated test core root is unavailable.');
    return $root;
}
function dev20SourceCleanup(string $root): void
{
    if (!is_dir($root) || is_link($root)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
dev20SourceExpect(is_string($ownerReal) && is_dir($ownerReal), 'Pass the immutable dev20 owner server source directory.');
dev20SourceExpect(is_string($adapterReal) && is_file($adapterReal), 'The candidate Department adapter is unavailable.');
$manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
dev20SourceExpect(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.20', 'Owner root is not the exact dev20 source.');

require_once dirname(__DIR__, 2) . '/lib/protocol.php';
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev20-source-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$stagedOwnerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
if (!mkdir($stagedOwnerRoot, 0700, true)) throw new RuntimeException('Could not create the isolated dev20 source stage.');
$GLOBALS['DEV20_TEST_CORE_ROOT'] = $coreRoot;

try {
    require $adapterReal;
    $expectedHashes = [
        'manifest.json' => '5ca4a5f2ef5bf0521a73b4e31ee3a44c82946b1f669496108219d4d46b48a843',
        'lib/runtime.php' => 'fc95fde88d422a70c5eb3caafcfdba5f3089cf05d4d5ec73d98faeea4e6b2c15',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '3ba80aede8d294439c56f9ed92c35cdfd7d1fac2684cbd28b0fb8dc8d8987a26',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => 'ba1405b262a60f913c2ec4a7349464a6b5400de59802a247844d8a34d2446a41',
        'lib/offscreen_host.php' => '7c036bf86978acc8349742ba4a172d0dcc4a8ef9b8ab8b2b97b321686bc09bdb',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ];
    $adapterHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-dev.20'] ?? null;
    dev20SourceExpect(is_array($adapterHashes), 'The adapter does not pin dev20.');
    $expectedPaths = array_keys($expectedHashes);
    $adapterPaths = array_keys($adapterHashes);
    sort($expectedPaths, SORT_STRING);
    sort($adapterPaths, SORT_STRING);
    dev20SourceExpect($adapterPaths === $expectedPaths, 'The adapter dev20 execution closure differs from the frozen owner closure.');
    foreach ($expectedHashes as $relative => $expectedHash) {
        dev20SourceExpect(($adapterHashes[$relative] ?? null) === $expectedHash, 'The dev20 owner pin differs: ' . $relative);
    }
    $sourceFiles = qd_correspondence_source_files($ownerReal);
    $actualPaths = array_keys($sourceFiles);
    sort($actualPaths, SORT_STRING);
    dev20SourceExpect($actualPaths === $expectedPaths, 'The dev20 source closure is incomplete or overbroad.');
    dev20SourceExpect(qd_correspondence_manifest_ready($ownerReal), 'The exact dev20 owner closure did not pass the adapter manifest check.');
    $identity = qd_correspondence_current_source_identity($ownerReal);
    dev20SourceExpect(is_array($identity) && ($identity['version'] ?? null) === '0.1.0-dev.20', 'The exact dev20 owner source identity was not recognized.');

    foreach ($sourceFiles as $relative => $source) {
        $sourceReal = realpath($source);
        dev20SourceExpect(is_string($sourceReal) && !is_link($source) && str_starts_with($sourceReal, $ownerReal . DIRECTORY_SEPARATOR), 'Owner closure path escaped its exact source root: ' . $relative);
        $target = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true)) throw new RuntimeException('Could not create a staged owner directory.');
        if (!copy($sourceReal, $target)) throw new RuntimeException('Could not stage owner file: ' . $relative);
    }
    dev20SourceExpect(qd_correspondence_runtime_ready(), 'Runtime readiness did not accept the exact staged dev20 owner closure.');

    $tamperPath = $stagedOwnerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'offscreen.php';
    if (file_put_contents($tamperPath, "<?php\n// pin drift\n") === false) throw new RuntimeException('Could not mutate the isolated source drift fixture.');
    dev20SourceExpect(!qd_correspondence_runtime_ready(), 'Runtime readiness accepted drift in a pinned dev20 source file.');

    dev20SourceExpect(qd_correspondence_version_uses_restore_fence('0.1.0-dev.18'), 'The dev18 restore fence was not retained.');
    dev20SourceExpect(qd_correspondence_version_uses_restore_fence('0.1.0-dev.20'), 'The dev20 restore fence is not enabled.');
    dev20SourceExpect(!qd_correspondence_version_uses_restore_fence('0.1.0-dev.19'), 'The adapter must not claim an unsupported dev19 restore fence.');
    dev20SourceExpect(qd_correspondence_version_supports_signed_unknown('0.1.0-dev.17')
        && qd_correspondence_version_supports_signed_unknown('0.1.0-dev.18')
        && qd_correspondence_version_supports_signed_unknown('0.1.0-dev.20'), 'Signed-unknown compatibility regressed for a pinned release.');
    dev20SourceExpect(!qd_correspondence_version_supports_signed_unknown('0.1.0-dev.19'), 'The adapter must not claim unsupported dev19 signed-unknown behavior.');
    dev20SourceExpect(qd_correspondence_version_supports_operator_close('0.1.0-dev.20')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.18')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.19'), 'Operator-close support must be exact dev20 only.');

    $unsupportedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'unsupported';
    if (!mkdir($unsupportedRoot, 0700, true)) throw new RuntimeException('Could not create the unsupported-version fixture.');
    if (file_put_contents($unsupportedRoot . DIRECTORY_SEPARATOR . 'manifest.json', '{"name":"correspondence","version":"0.1.0-dev.19"}') === false) {
        throw new RuntimeException('Could not write the unsupported-version fixture.');
    }
    dev20SourceExpect(qd_correspondence_source_files($unsupportedRoot) === [] && !qd_correspondence_manifest_ready($unsupportedRoot), 'An unpinned dev19 source must remain unavailable.');
    fwrite(STDOUT, "PASS: exact dev20 closure, fenced readiness, supported capabilities, and dev19 refusal\n");
} finally {
    unset($GLOBALS['DEV20_TEST_CORE_ROOT']);
    dev20SourceCleanup($tempRoot);
}
