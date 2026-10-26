<?php
declare(strict_types=1);

const QD_PCV_OVERLAY_VERSION = '0.1.24';
const QD_PCV_OVERLAY_TAG = 'private_conversation-v0.1.24';
const QD_PCV_OVERLAY_COMMIT = 'ce0a998773237a9fc91f6914725a327d9e621718';
const QD_PCV_OVERLAY_LEGACY_VERSION = '0.1.23';
const QD_PCV_OVERLAY_LEGACY_COMMIT = '77265202d7f4a47b88e1eb88131b21f4b0752020';
const QD_PCV_OVERLAY_LEGACY_ARCHIVE = 'pcv-overlay-backup-v0.1.23.php';
const QD_PCV_OVERLAY_BACKUP_SCHEMA = 1;
const QD_PCV_OVERLAY_MAX_SOURCE_BYTES = 524288;
const QD_PCV_OVERLAY_MAX_BACKUP_BYTES = 1048576;

final class QDPCVOverlayFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

function qd_pcv_overlay_pins(): array
{
    return [
        'installation_guard.php' => [
            'base' => '082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d',
            'post' => '082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d',
        ],
        'log.php' => [
            'base' => '0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56',
            'post' => '0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56',
        ],
        'manifest.json' => [
            'base' => '929a0b67050c3bab4ab1362f34997651896e07f9c8cd491a6e45fb7d9dca1ff0',
            'post' => '929a0b67050c3bab4ab1362f34997651896e07f9c8cd491a6e45fb7d9dca1ff0',
        ],
        'preprocessing.php' => [
            'base' => '5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c',
            'post' => '01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950',
        ],
        'reflection.php' => [
            'base' => 'd33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540',
            'post' => 'd33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540',
        ],
        'scope.php' => [
            'base' => 'e21fd73985424c099332dcbb035e3587c72ab78a21e21781669978dc25e39686',
            'post' => '1aac2c5124f8886cf87f22e26c621906fdd6c569d66c08b908406fd64d67865f',
        ],
        'state.php' => [
            'base' => '49c2b53466572015e77117653739966377267b5b50ec4514155e53256bca7361',
            'post' => 'b64277236a520b5464a26becc9c7d15dce233fdf2e3f4c99201c568da22e16d6',
        ],
    ];
}

function qd_pcv_overlay_legacy_pins(): array
{
    return [
        'preprocessing.php' => [
            'base' => '5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c',
            'post' => '01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950',
        ],
        'scope.php' => [
            'base' => '763ff62b129086f7c3d91fbccbb80e8db0ec71b04d11fc150c4881420bbe6992',
            'post' => 'aa800deb4a34e0a77663e938b80a11d748978fc1b420a708c1c9125fce9bf398',
        ],
        'state.php' => [
            'base' => '142f5a026740dfb629af925005c7e931396aa3dcde0d534002fb42b545c5664d',
            'post' => '3fbc48a0f25178011f0b838cc01d3664932822695d7d39c35d49cbc11fa057c1',
        ],
    ];
}

function qd_pcv_overlay_result(string $state, ?string $reason = null): array
{
    return ['state' => $state, 'reason' => $reason];
}

function qd_pcv_overlay_same_path(string $left, string $right): bool
{
    return function_exists('qd_path_key')
        ? qd_path_key($left) === qd_path_key($right)
        : (DIRECTORY_SEPARATOR === '\\'
            ? strtolower(str_replace('/', '\\', $left)) === strtolower(str_replace('/', '\\', $right))
            : $left === $right);
}

function qd_pcv_overlay_require(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new QDPCVOverlayFailure($reason);
    }
}

function qd_pcv_overlay_read_stage(string $stageRoot): array
{
    $manifestPath = $stageRoot . DIRECTORY_SEPARATOR . 'source-manifest.json';
    if (is_link($stageRoot) || !is_dir($stageRoot) || !is_file($manifestPath) || is_link($manifestPath)) {
        throw new QDPCVOverlayFailure('staged_source_missing');
    }
    $canonicalStage = realpath($stageRoot);
    $sourceSize = @filesize($manifestPath);
    if ($canonicalStage === false || !qd_pcv_overlay_same_path($canonicalStage, $stageRoot)
        || !is_int($sourceSize) || $sourceSize < 1 || $sourceSize > 65536) {
        throw new QDPCVOverlayFailure('staged_source_missing');
    }
    $rawManifest = @file_get_contents($manifestPath);
    if (!is_string($rawManifest)) {
        throw new QDPCVOverlayFailure('staged_source_missing');
    }
    try {
        $manifest = json_decode($rawManifest, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new QDPCVOverlayFailure('staged_source_missing');
    }

    $expectedTopKeys = ['schemaVersion', 'upstream', 'stagedSourceDirectory', 'installDirectory', 'files'];
    $upstream = is_array($manifest) ? ($manifest['upstream'] ?? null) : null;
    $expectedFiles = qd_pcv_overlay_pins();
    $fileEntries = is_array($manifest) ? ($manifest['files'] ?? null) : null;
    qd_pcv_overlay_require(is_array($manifest) && array_keys($manifest) === $expectedTopKeys
        && ($manifest['schemaVersion'] ?? null) === 1
        && is_array($upstream) && array_keys($upstream) === ['version', 'tag', 'commit']
        && ($upstream['version'] ?? null) === QD_PCV_OVERLAY_VERSION
        && ($upstream['tag'] ?? null) === QD_PCV_OVERLAY_TAG
        && ($upstream['commit'] ?? null) === QD_PCV_OVERLAY_COMMIT
        && ($manifest['stagedSourceDirectory'] ?? null) === 'pcv-companion/private-conversation-v0.1.24/server'
        && ($manifest['installDirectory'] ?? null) === 'ext/private_conversation'
        && is_array($fileEntries) && array_is_list($fileEntries) && count($fileEntries) === count($expectedFiles),
        'staged_source_missing');

    $sourceBytes = [];
    $index = 0;
    foreach ($expectedFiles as $relative => $pins) {
        $entry = $fileEntries[$index] ?? null;
        qd_pcv_overlay_require(is_array($entry)
            && array_keys($entry) === ['path', 'baseSha256', 'postimageSha256', 'modified']
            && ($entry['path'] ?? null) === $relative
            && ($entry['baseSha256'] ?? null) === $pins['base']
            && ($entry['postimageSha256'] ?? null) === $pins['post']
            && ($entry['modified'] ?? null) === !hash_equals($pins['base'], $pins['post']),
            'staged_source_missing');
        $sourcePath = $stageRoot . DIRECTORY_SEPARATOR . $relative;
        $size = @filesize($sourcePath);
        if (is_link($sourcePath) || !is_file($sourcePath) || !is_int($size) || $size < 1 || $size > QD_PCV_OVERLAY_MAX_SOURCE_BYTES) {
            throw new QDPCVOverlayFailure('staged_source_missing');
        }
        $bytes = @file_get_contents($sourcePath);
        if (!is_string($bytes) || !hash_equals($pins['post'], hash('sha256', $bytes))) {
            throw new QDPCVOverlayFailure('staged_source_missing');
        }
        $sourceBytes[$relative] = $bytes;
        $index++;
    }
    return ['files' => $sourceBytes, 'pins' => $expectedFiles];
}

function qd_pcv_overlay_read_owner(string $ownerRoot, array $pins): array
{
    $manifestPath = $ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) {
        throw new QDPCVOverlayFailure('owner_incompatible');
    }
    $manifestBytes = @file_get_contents($manifestPath);
    if (!is_string($manifestBytes)) {
        throw new QDPCVOverlayFailure('owner_incompatible');
    }
    try {
        $ownerManifest = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new QDPCVOverlayFailure('owner_incompatible');
    }
    qd_pcv_overlay_require(is_array($ownerManifest)
        && ($ownerManifest['name'] ?? null) === 'private_conversation'
        && ($ownerManifest['version'] ?? null) === QD_PCV_OVERLAY_VERSION
        && ($ownerManifest['schema_version'] ?? null) === 2
        && ($ownerManifest['git_repo'] ?? null) === 'Francisco-boop-001/CHIM-PrivateConversation',
        'owner_incompatible');

    $current = [];
    $allPostimage = true;
    $hasPostimage = false;
    foreach ($pins as $relative => $expected) {
        $path = $ownerRoot . DIRECTORY_SEPARATOR . $relative;
        $size = @filesize($path);
        if (is_link($path) || !is_file($path) || !is_int($size) || $size < 1 || $size > QD_PCV_OVERLAY_MAX_SOURCE_BYTES) {
            throw new QDPCVOverlayFailure('owner_incompatible');
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new QDPCVOverlayFailure('owner_incompatible');
        }
        $hash = hash('sha256', $bytes);
        $knownPrevious = is_string($expected['previous'] ?? null) && hash_equals($expected['previous'], $hash);
        if (!hash_equals($expected['base'], $hash) && !hash_equals($expected['post'], $hash) && !$knownPrevious) {
            throw new QDPCVOverlayFailure('owner_incompatible');
        }
        $current[$relative] = ['bytes' => $bytes, 'hash' => $hash];
        $allPostimage = $allPostimage && hash_equals($expected['post'], $hash);
        $hasPostimage = $hasPostimage || (!hash_equals($expected['base'], $expected['post']) && !hash_equals($expected['base'], $hash));
    }

    $guardPath = $ownerRoot . DIRECTORY_SEPARATOR . 'installation_guard.php';
    $guard = $current['installation_guard.php']['bytes'] ?? '';
    qd_pcv_overlay_require(str_contains($guard, 'PCV_INSTALLATION_GUARD_MARKER_V120'), 'owner_incompatible');
    return ['current' => $current, 'allPostimage' => $allPostimage, 'hasPostimage' => $hasPostimage];
}

function qd_pcv_overlay_read_backup_raw(string $backupPath): ?string
{
    if (!file_exists($backupPath) && !is_link($backupPath)) {
        return null;
    }
    if (is_link($backupPath) || !is_file($backupPath)) {
        throw new QDPCVOverlayFailure('unsafe_path');
    }
    $size = @filesize($backupPath);
    if (!is_int($size) || $size < strlen(QD_GUARDED_JSON_PREFIX) || $size > QD_PCV_OVERLAY_MAX_BACKUP_BYTES) {
        throw new QDPCVOverlayFailure('write_failed');
    }
    $raw = @file_get_contents($backupPath);
    if (!is_string($raw)) {
        throw new QDPCVOverlayFailure('write_failed');
    }
    return $raw;
}

function qd_pcv_overlay_decode_backup(string $raw): array
{
    if (!str_starts_with($raw, QD_GUARDED_JSON_PREFIX)) {
        throw new QDPCVOverlayFailure('write_failed');
    }
    $json = substr($raw, strlen(QD_GUARDED_JSON_PREFIX));
    try {
        $state = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $canonical = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new QDPCVOverlayFailure('write_failed');
    }
    qd_pcv_overlay_require(is_array($state) && is_string($canonical) && hash_equals($json, $canonical), 'write_failed');
    return $state;
}

function qd_pcv_overlay_validate_backup_state(
    array $state,
    string $chimRoot,
    string $pcvRoot,
    string $ownerVersion,
    string $ownerCommit,
    array $pins,
    array $expectedNames
): array {
    qd_pcv_overlay_require(array_keys($state) === ['schemaVersion', 'chimRoot', 'pcvRoot', 'ownerVersion', 'ownerCommit', 'files']
        && ($state['schemaVersion'] ?? null) === QD_PCV_OVERLAY_BACKUP_SCHEMA
        && ($state['chimRoot'] ?? null) === $chimRoot
        && ($state['pcvRoot'] ?? null) === $pcvRoot
        && ($state['ownerVersion'] ?? null) === $ownerVersion
        && ($state['ownerCommit'] ?? null) === $ownerCommit
        && is_array($state['files']) && array_keys($state['files']) === $expectedNames,
        'write_failed');

    $originals = [];
    foreach ($expectedNames as $relative) {
        $entry = $state['files'][$relative] ?? null;
        $expected = $pins[$relative] ?? null;
        qd_pcv_overlay_require(is_array($expected) && is_array($entry)
            && array_keys($entry) === ['sha256', 'bytesBase64']
            && ($entry['sha256'] ?? null) === $expected['base'] && is_string($entry['bytesBase64'] ?? null),
            'write_failed');
        $bytes = base64_decode($entry['bytesBase64'], true);
        qd_pcv_overlay_require(is_string($bytes) && hash_equals(base64_encode($bytes), $entry['bytesBase64'])
            && hash_equals($expected['base'], hash('sha256', $bytes)), 'write_failed');
        $originals[$relative] = $bytes;
    }
    return $originals;
}

function qd_pcv_overlay_legacy_backup_state(string $raw, string $chimRoot, string $ownerRoot): array
{
    $state = qd_pcv_overlay_decode_backup($raw);
    $pins = qd_pcv_overlay_legacy_pins();
    return qd_pcv_overlay_validate_backup_state($state, $chimRoot, $ownerRoot,
        QD_PCV_OVERLAY_LEGACY_VERSION, QD_PCV_OVERLAY_LEGACY_COMMIT,
        $pins, array_keys($pins));
}

function qd_pcv_overlay_backup_state(string $backupPath, string $chimRoot, array $pins): ?array
{
    $raw = qd_pcv_overlay_read_backup_raw($backupPath);
    if ($raw === null) {
        return null;
    }
    $state = qd_pcv_overlay_decode_backup($raw);
    $ownerRoot = $chimRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $expectedNames = array_keys(array_filter($pins, static fn(array $entry): bool => !hash_equals($entry['base'], $entry['post'])));
    return qd_pcv_overlay_validate_backup_state($state, $chimRoot, $ownerRoot,
        QD_PCV_OVERLAY_VERSION, QD_PCV_OVERLAY_COMMIT, $pins, $expectedNames);
}

function qd_pcv_overlay_snapshot_backup_path(string $path): array
{
    $raw = qd_pcv_overlay_read_backup_raw($path);
    return ['exists' => $raw !== null, 'raw' => $raw];
}

function qd_pcv_overlay_restore_backup_paths(string $privateRoot, array $snapshots, array $attempted): bool
{
    $restored = true;
    foreach (array_reverse($attempted, true) as $path => $attemptBytes) {
        try {
            $before = $snapshots[$path] ?? null;
            if (!is_array($before) || !is_bool($before['exists'] ?? null)
                || ($before['exists'] && !is_string($before['raw'] ?? null)) || !is_string($attemptBytes)) {
                return false;
            }
            $safe = qd_managed_file_path($path, $privateRoot, false);
            if (!is_string($safe) || is_link($path)) {
                return false;
            }
            $current = qd_pcv_overlay_read_backup_raw($safe);
            if ($before['exists']) {
                $beforeBytes = $before['raw'];
                if (is_string($current) && hash_equals($beforeBytes, $current)) {
                    continue;
                }
                if (!is_string($current) || !hash_equals($attemptBytes, $current)) {
                    return false;
                }
                qd_atomic_write($safe, $beforeBytes, false);
                $restoredBytes = qd_pcv_overlay_read_backup_raw($safe);
                if (!is_string($restoredBytes) || !hash_equals($beforeBytes, $restoredBytes)) {
                    return false;
                }
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (!hash_equals($attemptBytes, $current) || !@unlink($safe) || file_exists($safe) || is_link($safe)) {
                return false;
            }
        } catch (Throwable) {
            // Active backup is restored before its archive, so failure leaves the only known copy intact.
            return false;
        }
    }
    return $restored;
}

function qd_pcv_overlay_rollback(string $ownerRoot, array $changed, array $attemptOriginals, array $pins): bool
{
    $restored = true;
    foreach (array_reverse($changed) as $relative) {
        $path = $ownerRoot . DIRECTORY_SEPARATOR . $relative;
        $original = $attemptOriginals[$relative]['bytes'] ?? null;
        $originalHash = $attemptOriginals[$relative]['hash'] ?? null;
        if (is_link($path) || !is_file($path) || !is_string($original) || !is_string($originalHash)
            || !hash_equals($originalHash, hash('sha256', $original))) {
            $restored = false;
            continue;
        }
        $currentHash = @hash_file('sha256', $path);
        if (!is_string($currentHash)) {
            $restored = false;
            continue;
        }
        if (hash_equals($pins[$relative]['post'], $currentHash)) {
            try {
                qd_atomic_write($path, $original, false);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($path, true);
                }
            } catch (Throwable) {
                $restored = false;
                continue;
            }
        } elseif (!hash_equals($originalHash, $currentHash)) {
            $restored = false;
            continue;
        }
        $restoredHash = @hash_file('sha256', $path);
        if (!is_string($restoredHash) || !hash_equals($originalHash, $restoredHash)) {
            $restored = false;
        }
    }
    return $restored;
}

function qd_pcv_overlay_apply(array $settings): array
{
    $lock = null;
    $changed = [];
    $ownerRoot = null;
    $privateRoot = null;
    $pins = qd_pcv_overlay_pins();
    $originals = [];
    $attemptOriginals = [];
    $backupSnapshots = [];
    $backupAttempted = [];
    $failureReason = 'write_failed';

    try {
        qd_pcv_overlay_require(function_exists('qd_managed_file_path') && function_exists('qd_atomic_write')
            && defined('QD_GUARDED_JSON_PREFIX'), 'write_failed');
        $chimInput = $settings['chimRoot'] ?? null;
        $privateInput = $settings['privateRoot'] ?? null;
        $webRoot = $settings['webRoot'] ?? null;
        $pcvInput = $settings['pcvRoot'] ?? null;
        qd_pcv_overlay_require(is_string($chimInput) && $chimInput !== '' && is_string($privateInput) && $privateInput !== ''
            && is_string($webRoot) && !str_contains($webRoot, "\0") && ($pcvInput === null || is_string($pcvInput)), 'unsafe_path');

        $chimRoot = realpath($chimInput);
        qd_pcv_overlay_require(is_string($chimRoot) && is_dir($chimRoot) && qd_pcv_overlay_same_path($chimRoot, $chimInput), 'unsafe_path');
        $dataRoot = $chimRoot . DIRECTORY_SEPARATOR . 'data';
        $expectedPrivate = $dataRoot . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
        $privateRoot = realpath($privateInput);
        qd_pcv_overlay_require(!is_link($dataRoot) && !is_link($privateInput) && is_string($privateRoot) && is_dir($privateRoot)
            && qd_pcv_overlay_same_path($privateRoot, $privateInput) && qd_pcv_overlay_same_path($privateRoot, $expectedPrivate), 'unsafe_path');

        $extensionRoot = $chimRoot . DIRECTORY_SEPARATOR . 'ext';
        $ownerRoot = $extensionRoot . DIRECTORY_SEPARATOR . 'private_conversation';
        if (is_link($extensionRoot) || is_link($ownerRoot)) {
            throw new QDPCVOverlayFailure('unsafe_path');
        }
        if (!is_dir($ownerRoot)) {
            throw new QDPCVOverlayFailure('owner_missing');
        }
        $canonicalOwner = realpath($ownerRoot);
        qd_pcv_overlay_require(is_string($canonicalOwner) && qd_pcv_overlay_same_path($canonicalOwner, $ownerRoot), 'unsafe_path');
        if (!is_string($pcvInput) || $pcvInput === '') {
            throw new QDPCVOverlayFailure('unsafe_path');
        }
        $canonicalPcv = realpath($pcvInput);
        qd_pcv_overlay_require(!is_link($pcvInput) && is_string($canonicalPcv) && qd_pcv_overlay_same_path($canonicalPcv, $canonicalOwner), 'unsafe_path');

        $expectedPluginRoot = $extensionRoot . DIRECTORY_SEPARATOR . 'questionable_decisions';
        $expectedLib = $expectedPluginRoot . DIRECTORY_SEPARATOR . 'lib';
        $expectedSetup = $expectedPluginRoot . DIRECTORY_SEPARATOR . 'setup';
        $expectedStage = $expectedSetup . DIRECTORY_SEPARATOR . 'pcv-overlay';
        $canonicalLib = realpath(__DIR__);
        qd_pcv_overlay_require(!is_link($expectedPluginRoot) && !is_link($expectedLib) && !is_link($expectedSetup)
            && !is_link($expectedStage) && is_string($canonicalLib)
            && qd_pcv_overlay_same_path($canonicalLib, $expectedLib), 'unsafe_path');

        $lockPath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay.lock.php';
        $lockSafe = qd_managed_file_path($lockPath, $privateRoot, false);
        qd_pcv_overlay_require(is_string($lockSafe) && !is_link($lockPath), 'unsafe_path');
        $lock = @fopen($lockSafe, 'c+b');
        qd_pcv_overlay_require(is_resource($lock) && @flock($lock, LOCK_EX), 'write_failed');
        @chmod($lockSafe, 0600);
        $lockStat = @fstat($lock);
        qd_pcv_overlay_require(is_array($lockStat) && is_int($lockStat['size'] ?? null), 'write_failed');
        if ($lockStat['size'] === 0) {
            rewind($lock);
            qd_pcv_overlay_require(@fwrite($lock, QD_GUARDED_JSON_PREFIX) === strlen(QD_GUARDED_JSON_PREFIX)
                && @fflush($lock), 'write_failed');
            if (function_exists('fsync')) {
                qd_pcv_overlay_require(@fsync($lock), 'write_failed');
            }
        } else {
            qd_pcv_overlay_require($lockStat['size'] === strlen(QD_GUARDED_JSON_PREFIX), 'write_failed');
            rewind($lock);
            $lockPrefix = @fread($lock, strlen(QD_GUARDED_JSON_PREFIX));
            qd_pcv_overlay_require(is_string($lockPrefix) && hash_equals(QD_GUARDED_JSON_PREFIX, $lockPrefix), 'write_failed');
        }

        if (!is_dir($expectedStage)) {
            throw new QDPCVOverlayFailure('staged_source_missing');
        }
        $stageRoot = realpath($expectedStage);
        qd_pcv_overlay_require(is_string($stageRoot) && qd_pcv_overlay_same_path($stageRoot, $expectedStage), 'unsafe_path');
        $stage = qd_pcv_overlay_read_stage($stageRoot);
        $pins = $stage['pins'];
        $owner = qd_pcv_overlay_read_owner($ownerRoot, $pins);
        $modifiedNames = array_keys(array_filter($pins, static fn(array $entry): bool => !hash_equals($entry['base'], $entry['post'])));
        $backupPath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
        $backupSafe = qd_managed_file_path($backupPath, $privateRoot, false);
        qd_pcv_overlay_require(is_string($backupSafe) && !is_link($backupPath), 'unsafe_path');
        $archivePath = $privateRoot . DIRECTORY_SEPARATOR . QD_PCV_OVERLAY_LEGACY_ARCHIVE;
        $archiveSafe = qd_managed_file_path($archivePath, $privateRoot, false);
        qd_pcv_overlay_require(is_string($archiveSafe) && !is_link($archivePath), 'unsafe_path');
        $backupSnapshots = [
            $backupSafe => qd_pcv_overlay_snapshot_backup_path($backupSafe),
            $archiveSafe => qd_pcv_overlay_snapshot_backup_path($archiveSafe),
        ];
        $activeRaw = $backupSnapshots[$backupSafe]['raw'];
        $archiveRaw = $backupSnapshots[$archiveSafe]['raw'];

        $allBase = true;
        foreach ($pins as $relative => $entry) {
            $allBase = $allBase && hash_equals($entry['base'], $owner['current'][$relative]['hash']);
        }

        $archiveOriginals = null;
        if (is_string($archiveRaw)) {
            $archiveOriginals = qd_pcv_overlay_legacy_backup_state($archiveRaw, $chimRoot, $ownerRoot);
        }
        $activeKind = null;
        $activeLegacyOriginals = null;
        if (is_string($activeRaw)) {
            $activeState = qd_pcv_overlay_decode_backup($activeRaw);
            try {
                $originals = qd_pcv_overlay_validate_backup_state(
                    $activeState,
                    $chimRoot,
                    $ownerRoot,
                    QD_PCV_OVERLAY_VERSION,
                    QD_PCV_OVERLAY_COMMIT,
                    $pins,
                    $modifiedNames,
                );
                $activeKind = 'current';
            } catch (QDPCVOverlayFailure) {
                $activeLegacyOriginals = qd_pcv_overlay_legacy_backup_state($activeRaw, $chimRoot, $ownerRoot);
                $activeKind = 'legacy';
            }
        }

        if ($archiveOriginals !== null && $activeKind !== 'current') {
            qd_pcv_overlay_require($activeKind === 'legacy' && is_string($activeRaw)
                && is_string($archiveRaw) && hash_equals($activeRaw, $archiveRaw), 'write_failed');
        }
        if ($activeKind === 'legacy') {
            qd_pcv_overlay_require($allBase, 'owner_incompatible');
        } elseif ($activeKind === null) {
            qd_pcv_overlay_require($archiveRaw === null && $allBase, 'owner_incompatible');
        }

        if ($owner['allPostimage']) {
            qd_pcv_overlay_require($activeKind === 'current', 'owner_incompatible');
            return qd_pcv_overlay_result('already_enabled');
        }

        if ($activeKind === 'legacy' || $activeKind === null) {
            $originals = [];
            foreach ($modifiedNames as $relative) {
                qd_pcv_overlay_require(hash_equals($pins[$relative]['base'], $owner['current'][$relative]['hash']), 'owner_incompatible');
                $originals[$relative] = $owner['current'][$relative]['bytes'];
            }
        }
        if ($activeKind === 'current' && $owner['hasPostimage'] && $originals === []) {
            throw new QDPCVOverlayFailure('owner_incompatible');
        }

        $backupFiles = [];
        foreach ($modifiedNames as $relative) {
            $backupFiles[$relative] = ['sha256' => $pins[$relative]['base'], 'bytesBase64' => base64_encode($originals[$relative])];
        }
        $backupJson = json_encode([
            'schemaVersion' => QD_PCV_OVERLAY_BACKUP_SCHEMA,
            'chimRoot' => $chimRoot,
            'pcvRoot' => $ownerRoot,
            'ownerVersion' => QD_PCV_OVERLAY_VERSION,
            'ownerCommit' => QD_PCV_OVERLAY_COMMIT,
            'files' => $backupFiles,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $currentBackupRaw = QD_GUARDED_JSON_PREFIX . $backupJson;
        if (strlen($currentBackupRaw) > QD_PCV_OVERLAY_MAX_BACKUP_BYTES) {
            throw new QDPCVOverlayFailure('capacity');
        }
        if ($activeKind === 'legacy' && $archiveRaw === null) {
            $backupAttempted[$archiveSafe] = $activeRaw;
            qd_atomic_write($archiveSafe, $activeRaw, false);
        }
        if ($activeKind === 'legacy' || $activeKind === null) {
            $backupAttempted[$backupSafe] = $currentBackupRaw;
            qd_atomic_write($backupSafe, $backupJson, true);
        }
        $verifiedBackup = qd_pcv_overlay_backup_state($backupSafe, $chimRoot, $pins);
        qd_pcv_overlay_require($verifiedBackup === $originals, 'write_failed');
        if ($activeKind === 'legacy') {
            $verifiedArchive = qd_pcv_overlay_read_backup_raw($archiveSafe);
            qd_pcv_overlay_require(is_string($verifiedArchive) && hash_equals($activeRaw, $verifiedArchive), 'write_failed');
            qd_pcv_overlay_legacy_backup_state($verifiedArchive, $chimRoot, $ownerRoot);
        }

        foreach ($modifiedNames as $relative) {
            $path = $ownerRoot . DIRECTORY_SEPARATOR . $relative;
            qd_pcv_overlay_require(!is_link($path) && is_file($path), 'unsafe_path');
            $currentHash = @hash_file('sha256', $path);
            if (is_string($currentHash) && hash_equals($pins[$relative]['post'], $currentHash)) {
                continue;
            }
            qd_pcv_overlay_require(is_string($currentHash)
                && hash_equals($owner['current'][$relative]['hash'], $currentHash), 'owner_incompatible');
            $attemptOriginals[$relative] = $owner['current'][$relative];
            $changed[] = $relative;
            qd_atomic_write($path, $stage['files'][$relative], false);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            $writtenHash = @hash_file('sha256', $path);
            qd_pcv_overlay_require(is_string($writtenHash) && hash_equals($pins[$relative]['post'], $writtenHash), 'write_failed');
        }

        $verified = qd_pcv_overlay_read_owner($ownerRoot, $pins);
        qd_pcv_overlay_require($verified['allPostimage'], 'write_failed');
        $verifiedBackup = qd_pcv_overlay_backup_state($backupSafe, $chimRoot, $pins);
        qd_pcv_overlay_require($verifiedBackup === $originals, 'write_failed');
        return qd_pcv_overlay_result('enabled');
    } catch (Throwable $failure) {
        $failureReason = $failure instanceof QDPCVOverlayFailure ? $failure->reason : 'write_failed';
        $rollbackOkay = true;
        if ($changed !== [] && is_string($ownerRoot) && $attemptOriginals !== []) {
            if (!qd_pcv_overlay_rollback($ownerRoot, $changed, $attemptOriginals, $pins)) {
                $rollbackOkay = false;
            }
        }
        if ($backupAttempted !== [] && is_string($privateRoot)
            && !qd_pcv_overlay_restore_backup_paths($privateRoot, $backupSnapshots, $backupAttempted)) {
            $rollbackOkay = false;
        }
        if (!$rollbackOkay) {
            $failureReason = 'write_failed';
        }
    } finally {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    return qd_pcv_overlay_result('unavailable', $failureReason);
}
