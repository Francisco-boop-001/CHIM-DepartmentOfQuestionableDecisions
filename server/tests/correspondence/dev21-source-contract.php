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
        fwrite(STDERR, "usage: php -n dev21-source-contract.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

function dev21SourceExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function qd_runtime_core_root(): string
{
    $root = $GLOBALS['DEV21_TEST_CORE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') throw new RuntimeException('The isolated test core root is unavailable.');
    return $root;
}
function dev21SourceCleanup(string $root): void
{
    if (!is_dir($root) || is_link($root)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
dev21SourceExpect(is_string($ownerReal) && is_dir($ownerReal), 'Pass the immutable dev21 owner server source directory.');
dev21SourceExpect(is_string($adapterReal) && is_file($adapterReal), 'The candidate Department adapter is unavailable.');
$manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
dev21SourceExpect(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.21', 'Owner root is not the exact dev21 source.');

require_once dirname(__DIR__, 2) . '/lib/protocol.php';
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev21-source-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$stagedOwnerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
if (!mkdir($stagedOwnerRoot, 0700, true)) throw new RuntimeException('Could not create the isolated dev21 source stage.');
$GLOBALS['DEV21_TEST_CORE_ROOT'] = $coreRoot;

try {
    require $adapterReal;
    $expectedHashes = [
        'manifest.json' => 'cf2b7914e66ae6d864956a5df446aa21a9e87d4beebcd4153b214c4d40736541',
        'lib/runtime.php' => 'fc95fde88d422a70c5eb3caafcfdba5f3089cf05d4d5ec73d98faeea4e6b2c15',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '3ba80aede8d294439c56f9ed92c35cdfd7d1fac2684cbd28b0fb8dc8d8987a26',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '74fc0e80681fd105217ec0068ff6a531a66be81992c1e0cdbabf1051e68885ad',
        'lib/offscreen_host.php' => '7c036bf86978acc8349742ba4a172d0dcc4a8ef9b8ab8b2b97b321686bc09bdb',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ];
    $adapterHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-dev.21'] ?? null;
    dev21SourceExpect(is_array($adapterHashes), 'The adapter does not pin dev21.');
    $expectedPaths = array_keys($expectedHashes);
    $adapterPaths = array_keys($adapterHashes);
    sort($expectedPaths, SORT_STRING);
    sort($adapterPaths, SORT_STRING);
    dev21SourceExpect($adapterPaths === $expectedPaths, 'The adapter dev21 execution closure differs from the frozen owner closure.');
    foreach ($expectedHashes as $relative => $expectedHash) {
        dev21SourceExpect(($adapterHashes[$relative] ?? null) === $expectedHash, 'The dev21 owner pin differs: ' . $relative);
    }
    $sourceFiles = qd_correspondence_source_files($ownerReal);
    $actualPaths = array_keys($sourceFiles);
    sort($actualPaths, SORT_STRING);
    dev21SourceExpect($actualPaths === $expectedPaths, 'The dev21 source closure is incomplete or overbroad.');
    dev21SourceExpect(qd_correspondence_manifest_ready($ownerReal), 'The exact dev21 owner closure did not pass the adapter manifest check.');
    $identity = qd_correspondence_current_source_identity($ownerReal);
    dev21SourceExpect(is_array($identity) && ($identity['version'] ?? null) === '0.1.0-dev.21', 'The exact dev21 owner source identity was not recognized.');

    foreach ($sourceFiles as $relative => $source) {
        $sourceReal = realpath($source);
        dev21SourceExpect(is_string($sourceReal) && !is_link($source) && str_starts_with($sourceReal, $ownerReal . DIRECTORY_SEPARATOR), 'Owner closure path escaped its exact source root: ' . $relative);
        $target = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true)) throw new RuntimeException('Could not create a staged owner directory.');
        if (!copy($sourceReal, $target)) throw new RuntimeException('Could not stage owner file: ' . $relative);
    }
    dev21SourceExpect(qd_correspondence_runtime_ready(), 'Runtime readiness did not accept the exact staged dev21 owner closure.');

    $tamperPath = $stagedOwnerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'offscreen.php';
    if (file_put_contents($tamperPath, "<?php\n// pin drift\n") === false) throw new RuntimeException('Could not mutate the isolated source drift fixture.');
    dev21SourceExpect(!qd_correspondence_runtime_ready(), 'Runtime readiness accepted drift in a pinned dev21 source file.');
    if (!copy($ownerReal . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'offscreen.php', $tamperPath)) throw new RuntimeException('Could not restore the isolated source fixture.');
    $manifestPath = $stagedOwnerRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestBytes = file_get_contents($manifestPath);
    if (!is_string($manifestBytes) || file_put_contents($manifestPath, $manifestBytes . ' ', LOCK_EX) === false) throw new RuntimeException('Could not mutate the isolated manifest drift fixture.');
    dev21SourceExpect(!qd_correspondence_runtime_ready(), 'Runtime readiness accepted manifest drift in a pinned dev21 source file.');
    dev21SourceExpect(qd_correspondence_version_uses_restore_fence('0.1.0-dev.18'), 'The dev18 restore fence was not retained.');
    dev21SourceExpect(qd_correspondence_version_uses_restore_fence('0.1.0-dev.20'), 'The dev20 restore fence was not retained.');
    dev21SourceExpect(qd_correspondence_version_uses_restore_fence('0.1.0-dev.21'), 'The dev21 restore fence is not enabled.');
    dev21SourceExpect(!qd_correspondence_version_uses_restore_fence('0.1.0-dev.19')
        && !qd_correspondence_version_uses_restore_fence('0.1.0-dev.23'), 'The adapter must refuse unsupported restore-fence versions.');
    dev21SourceExpect(qd_correspondence_version_supports_signed_unknown('0.1.0-dev.17')
        && qd_correspondence_version_supports_signed_unknown('0.1.0-dev.18')
        && qd_correspondence_version_supports_signed_unknown('0.1.0-dev.20')
        && qd_correspondence_version_supports_signed_unknown('0.1.0-dev.21'), 'Signed-unknown compatibility regressed for a pinned release.');
    dev21SourceExpect(!qd_correspondence_version_supports_signed_unknown('0.1.0-dev.19')
        && !qd_correspondence_version_supports_signed_unknown('0.1.0-dev.23'), 'The adapter must refuse unsupported signed-unknown versions.');
    dev21SourceExpect(qd_correspondence_version_supports_operator_close('0.1.0-dev.20')
        && qd_correspondence_version_supports_operator_close('0.1.0-dev.21')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.17')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.18')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.19')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.23'), 'Operator-close support must remain limited to pinned versions.');

    foreach (['0.1.0-dev.19', '0.1.0-dev.23'] as $unsupportedVersion) {
        $unsupportedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'unsupported-' . str_replace('.', '-', $unsupportedVersion);
        if (!mkdir($unsupportedRoot, 0700, true)) throw new RuntimeException('Could not create the unsupported-version fixture.');
        if (file_put_contents($unsupportedRoot . DIRECTORY_SEPARATOR . 'manifest.json', '{"name":"correspondence","version":"' . $unsupportedVersion . '"}') === false) {
            throw new RuntimeException('Could not write the unsupported-version fixture.');
        }
        dev21SourceExpect(qd_correspondence_source_files($unsupportedRoot) === [] && !qd_correspondence_manifest_ready($unsupportedRoot), 'An unpinned ' . $unsupportedVersion . ' source must remain unavailable.');
    }
    fwrite(STDOUT, "PASS: exact dev21 closure, fenced readiness, supported capabilities, and unsupported-version refusal\n");
} finally {
    unset($GLOBALS['DEV21_TEST_CORE_ROOT']);
    dev21SourceCleanup($tempRoot);
}
