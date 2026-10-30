<?php
declare(strict_types=1);

$ownerRoot = '';
$handoffPath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'owner-dev31-handoff.json';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--handoff' && isset($argv[$i + 1])) {
        $handoffPath = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev31-source-contract.php --owner-root OWNER_SERVER_DIR [--handoff FROZEN_HANDOFF.json] [--adapter PATH]\n");
        exit(2);
    }
}

$ownerRoot = realpath($ownerRoot) ?: '';
$handoffPath = realpath($handoffPath) ?: '';
$adapterPath = realpath($adapterPath) ?: '';
if ($ownerRoot === '' || $handoffPath === '' || $adapterPath === ''
    || is_link($ownerRoot) || is_link($handoffPath) || !is_file($handoffPath)) {
    fwrite(STDERR, "A frozen dev.31 owner source root, handoff, and adapter are required.\n");
    exit(2);
}
// Historical handoff data supplies expected source pins only; proofSoFar is not runtime acceptance.
$handoffBytes = @file_get_contents($handoffPath);
$handoffHash = @hash_file('sha256', $handoffPath);
if (!is_string($handoffBytes) || !is_string($handoffHash)
    || !hash_equals('53d4d323dc4b98dd05d823e48c250c3abf8c15eafe58a7866679c78e4a3116c4', $handoffHash)) {
    fwrite(STDERR, "The exact frozen owner handoff is required.\n");
    exit(2);
}
$handoff = json_decode($handoffBytes, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($handoff) || ($handoff['status'] ?? null) !== 'FROZEN_RUNTIME_CANDIDATE_NOT_PUBLISHED'
    || ($handoff['sourceCommit'] ?? null) !== '0d0acc1ad38108dc1e2e4fae9d9ab755b3dcff6c'
    || ($handoff['ownerVersion'] ?? null) !== '0.1.0-dev.31' || !is_array($handoff['pins'] ?? null)) {
    fwrite(STDERR, "The handoff identity is not the frozen dev.31 candidate.\n");
    exit(2);
}
if (function_exists('pg_query')) {
    fwrite(STDERR, "Run with php -n so the source contract remains offline.\n");
    exit(2);
}

function dev31Expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

require_once $adapterPath;
$version = '0.1.0-dev.31';
$expectedPins = [];
foreach ($handoff['pins'] as $relative => $pin) {
    dev31Expect(is_string($relative) && is_array($pin)
        && is_int($pin['bytes'] ?? null)
        && is_string($pin['sha256'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256']) === 1,
        'The frozen handoff pin entry is malformed.');
    $expectedPins[$relative] = $pin['sha256'];
}
$versionPins = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$version] ?? null;
dev31Expect(count($expectedPins) === 21 && $versionPins === $expectedPins,
    'The dev.31 adapter map must exactly match all 21 frozen owner pins.');

$files = qd_correspondence_source_files($ownerRoot);
dev31Expect(array_keys($files) === array_keys($expectedPins),
    'The dev.31 source closure must include every frozen owner file, including preprocessing.php.');
dev31Expect(qd_correspondence_manifest_ready($ownerRoot),
    'The actual frozen dev.31 owner tree failed its source integrity check.');
$identity = qd_correspondence_current_source_identity($ownerRoot);
dev31Expect(is_array($identity) && ($identity['version'] ?? null) === $version
    && ($identity['manifestHash'] ?? null) === $expectedPins['manifest.json'],
    'The loaded source identity does not match the frozen dev.31 owner.');
foreach ($handoff['pins'] as $relative => $pin) {
    $path = $files[$relative] ?? null;
    dev31Expect(is_string($path) && is_file($path) && !is_link($path)
        && filesize($path) === $pin['bytes'] && hash_file('sha256', $path) === $pin['sha256'],
        'A source file differs from the frozen owner pin: ' . $relative);
}

foreach (['0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25', '0.1.0-dev.26', '0.1.0-dev.27', '0.1.0-dev.28', '0.1.0-dev.29', '0.1.0-dev.30', $version] as $supported) {
    dev31Expect(isset(QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$supported])
        && qd_correspondence_version_uses_restore_fence($supported),
        'A pinned restore-fence source is missing: ' . $supported);
}
foreach (['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25', '0.1.0-dev.26', '0.1.0-dev.27', '0.1.0-dev.28', '0.1.0-dev.29', '0.1.0-dev.30', $version] as $supported) {
    dev31Expect(qd_correspondence_version_supports_signed_unknown($supported),
        'Signed-unknown support is missing for a pinned source: ' . $supported);
}
foreach (['0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25', '0.1.0-dev.26', '0.1.0-dev.27', '0.1.0-dev.28', '0.1.0-dev.29', '0.1.0-dev.30', $version] as $supported) {
    dev31Expect(qd_correspondence_version_supports_operator_close($supported),
        'Operator-close support is missing for a pinned source: ' . $supported);
}
foreach (['0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25', '0.1.0-dev.26', '0.1.0-dev.27', '0.1.0-dev.28', '0.1.0-dev.29', '0.1.0-dev.30', $version] as $supported) {
    dev31Expect(qd_correspondence_version_supports_companion_scheduler($supported),
        'Companion scheduler support is missing for a pinned source: ' . $supported);
}
dev31Expect(qd_correspondence_version_supports_pending_resume('0.1.0-dev.30')
    && qd_correspondence_version_supports_pending_resume($version)
    && !qd_correspondence_version_supports_pending_resume('0.1.0-dev.29'),
    'Pending discovery and resume must remain limited to the exact dev.30/dev.31 owner versions.');
foreach (['0.1.0-dev.19', '0.1.0-dev.32'] as $unsupported) {
    dev31Expect(!isset(QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$unsupported])
        && !qd_correspondence_version_uses_restore_fence($unsupported)
        && !qd_correspondence_version_supports_signed_unknown($unsupported)
        && !qd_correspondence_version_supports_operator_close($unsupported)
        && !qd_correspondence_version_supports_companion_scheduler($unsupported)
        && !qd_correspondence_version_supports_pending_resume($unsupported),
        'An unsupported owner version was enabled: ' . $unsupported);
}

$tempBase = realpath(sys_get_temp_dir());
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev31-' . bin2hex(random_bytes(8));
if (!is_string($tempBase) || !@mkdir($tempRoot, 0700)) throw new RuntimeException('Could not create the unknown-version fixture.');
try {
    $unknownManifest = $tempRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $unknownBytes = '{"name":"correspondence","version":"0.1.0-dev.32"}';
    dev31Expect(@file_put_contents($unknownManifest, $unknownBytes, LOCK_EX) === strlen($unknownBytes),
        'Could not write the unknown-version fixture.');
    dev31Expect(qd_correspondence_source_files($tempRoot) === [] && !qd_correspondence_manifest_ready($tempRoot),
        'An unpinned source manifest must fail closed.');
} finally {
    $realTemp = realpath($tempRoot);
    dev31Expect(is_string($realTemp) && strcasecmp(dirname($realTemp), $tempBase) === 0
        && str_starts_with(basename($realTemp), 'qd-correspondence-dev31-') && !is_link($tempRoot),
        'Refusing unsafe unknown-version fixture cleanup.');
    @unlink($realTemp . DIRECTORY_SEPARATOR . 'manifest.json');
    if (!@rmdir($realTemp)) throw new RuntimeException('Could not remove the unknown-version fixture.');
}

fwrite(STDOUT, "PASS: frozen dev.31 source closure, exact capability gates, pending resume scope, and unknown-version refusal\n");