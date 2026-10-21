<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

function pairing_path_has_link(string $path): bool
{
    if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")) return true;
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    if (PHP_OS_FAMILY === 'Windows') {
        if (str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) return true;
        if (preg_match('/\A[A-Za-z]:\\\\/', $path) === 1) {
            $current = substr($path, 0, 3);
            $rest = substr($path, 3);
        } elseif (preg_match('/\A[A-Za-z]:/', $path) === 1) {
            return true;
        } else {
            $cwd = getcwd();
            if (!is_string($cwd)) return true;
            $path = $cwd . DIRECTORY_SEPARATOR . $path;
            return pairing_path_has_link($path);
        }
    } else {
        if (str_starts_with($path, '//')) return true;
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $cwd = getcwd();
            if (!is_string($cwd)) return true;
            $path = $cwd . DIRECTORY_SEPARATOR . $path;
        }
        $current = DIRECTORY_SEPARATOR;
        $rest = ltrim($path, DIRECTORY_SEPARATOR);
    }
    foreach (explode(DIRECTORY_SEPARATOR, $rest) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            $current = dirname($current);
            continue;
        }
        $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
        if (is_link($current)) return true;
    }
    return false;
}

function pairing_within(string $root, string $path): bool
{
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        return str_starts_with(strtolower($path), strtolower($prefix));
    }
    return str_starts_with($path, $prefix);
}

function pairing_directory(string $path): ?string
{
    if (pairing_path_has_link($path)) return null;
    $real = realpath($path);
    return is_string($real) && is_dir($real) && !is_link($real) ? $real : null;
}

function pairing_relative_file(string $root, string $relative, int $maxBytes = 16777216): ?string
{
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, ':')) return null;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return null;
    }
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (pairing_path_has_link($path) || !is_file($path) || is_link($path)) return null;
    $real = realpath($path);
    if (!is_string($real) || !pairing_within($root, $real) || is_link($real)) return null;
    $size = @filesize($real);
    return is_int($size) && $size <= $maxBytes ? $real : null;
}

function pairing_parse_arguments(array $argv): ?array
{
    $values = [];
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $option = $argv[$i];
        if (!in_array($option, ['--owner-root', '--department-root'], true) || isset($values[$option])) return null;
        $value = $argv[++$i] ?? null;
        if (!is_string($value) || $value === '' || str_starts_with($value, '--') || strlen($value) > 4096) return null;
        $values[$option] = $value;
    }
    if (!isset($values['--owner-root'])) return null;
    return [
        'ownerRoot' => $values['--owner-root'],
        'departmentRoot' => $values['--department-root'] ?? dirname(__DIR__, 2),
    ];
}

function pairing_manifest(string $ownerRoot): array
{
    $path = pairing_relative_file($ownerRoot, 'manifest.json', 8192);
    if (!is_string($path)) return ['manifest' => null, 'version' => null, 'sha256' => null];
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)) return ['manifest' => null, 'version' => null, 'sha256' => null];
    $manifest = json_decode($bytes, true, 8);
    return [
        'manifest' => is_array($manifest) ? $manifest : null,
        'version' => is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : null,
        'sha256' => hash('sha256', $bytes),
    ];
}

function pairing_hash_files(array $files, ?string $root = null): ?array
{
    $hashes = [];
    if (count($files) > 64) return null;
    $totalBytes = 0;
    foreach ($files as $relative => $path) {
        if (!is_string($relative) || !is_string($path)) return null;
        if (pairing_path_has_link($path) || !is_file($path) || is_link($path)) return null;
        $real = realpath($path);
        if (!is_string($real) || ($root !== null && !pairing_within($root, $real))) return null;
        if (PHP_OS_FAMILY === 'Windows' ? strtolower($real) !== strtolower($path) : $real !== $path) return null;
        $size = @filesize($path);
        if (!is_int($size) || $size > 16777216 || ($totalBytes += $size) > 67108864) return null;
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) return null;
        $hashes[$relative] = strtolower($hash);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function pairing_create_stage(string $tempBase, array $sourcePaths, array $sourceHashes): ?array
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $tempRoot = $tempBase . DIRECTORY_SEPARATOR . 'department-owner-pairing-' . bin2hex(random_bytes(12));
        if (@mkdir($tempRoot, 0700)) break;
        $tempRoot = '';
    }
    if ($tempRoot === '' || !is_dir($tempRoot) || is_link($tempRoot)) return null;
    $failed = ['tempRoot' => $tempRoot, 'failed' => true];
    $coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
    $extensionRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
    if (!@mkdir($extensionRoot, 0700, true) || pairing_path_has_link($extensionRoot)) return $failed;
    foreach ($sourcePaths as $relative => $source) {
        if (!isset($sourceHashes[$relative])) return $failed;
        $target = $extensionRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0700, true)) return $failed;
        if (pairing_path_has_link($targetDirectory) || !@copy($source, $target) || is_link($target)) return $failed;
        $targetReal = realpath($target);
        if (!is_string($targetReal) || !pairing_within($extensionRoot, $targetReal)) return $failed;
    }
    return ['tempRoot' => $tempRoot, 'coreRoot' => $coreRoot, 'extensionRoot' => $extensionRoot, 'failed' => false];
}

function pairing_remove_tree(string $path, string $ownedRoot): bool
{
    $real = realpath($path);
    $isOwnedRoot = is_string($real) && (PHP_OS_FAMILY === 'Windows'
        ? strtolower($real) === strtolower($ownedRoot)
        : $real === $ownedRoot);
    if (!is_string($real) || is_link($path) || (!$isOwnedRoot && !pairing_within($ownedRoot, $real)) || pairing_path_has_link($path)) return false;
    try {
        foreach (new DirectoryIterator($real) as $entry) {
            if ($entry->isDot()) continue;
            $child = $entry->getPathname();
            if ($entry->isLink()) {
                if (!@unlink($child)) return false;
            } elseif ($entry->isDir()) {
                if (!pairing_remove_tree($child, $ownedRoot)) return false;
            } elseif (!@unlink($child)) {
                return false;
            }
        }
    } catch (Throwable) {
        return false;
    }
    return @rmdir($real);
}

function qd_runtime_core_root(): string
{
    $root = $GLOBALS['pairing_runtime_core_root'] ?? null;
    if (!is_string($root)) throw new RuntimeException('runtime_root_unavailable');
    return $root;
}

function pairing_report(): array
{
    return [
        'schemaVersion' => 1,
        'ready' => false,
        'errorReason' => null,
        'owner' => ['version' => null, 'manifestSha256' => null, 'selectedClosurePins' => []],
        'department' => [
            'protocol' => ['file' => 'lib/protocol.php', 'sha256' => null],
            'adapter' => ['file' => 'adapters/correspondence.php', 'sha256' => null],
        ],
        'checks' => [
            'adapterLoaded' => false,
            'manifestReady' => false,
            'sourceIdentityReady' => false,
            'runtimeReady' => false,
            'stagedClosureMatches' => false,
        ],
        'limits' => [
            'sourceOnly' => true,
            'database' => false,
            'http' => false,
            'skyrim' => false,
            'ownerRuntimeLoaded' => false,
        ],
    ];
}

final class PairingRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

function pairing_refuse(string $reason): never
{
    throw new PairingRefusal($reason);
}

function pairing_same_path(string $left, string $right): bool
{
    return PHP_OS_FAMILY === 'Windows'
        ? strtolower($left) === strtolower($right)
        : $left === $right;
}


function pairing_at_or_below(string $root, string $path): bool
{
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return pairing_same_path($root, $path) || pairing_within($root, $path);
}

function pairing_owner_runtime_loaded(array $roots): bool
{
    foreach ($roots as $root) {
        if (!is_string($root)) continue;
        $realRoot = realpath($root);
        if (!is_string($realRoot)) continue;
        foreach (get_included_files() as $includedPath) {
            if (!is_string($includedPath)) continue;
            if (pairing_at_or_below($realRoot, $includedPath)) return true;
            $realIncluded = realpath($includedPath);
            if (is_string($realIncluded) && pairing_at_or_below($realRoot, $realIncluded)) return true;
        }
    }
    return false;
}

function pairing_department_stable(string $protocolPath, string $adapterPath, string $protocolHash, string $adapterHash): bool
{
    $protocolNow = @hash_file('sha256', $protocolPath);
    $adapterNow = @hash_file('sha256', $adapterPath);
    return is_string($protocolNow) && is_string($adapterNow)
        && hash_equals($protocolHash, strtolower($protocolNow))
        && hash_equals($adapterHash, strtolower($adapterNow));
}

function pairing_check(array $argv): array
{
    $report = pairing_report();
    $tempBase = null;
    $tempRoot = null;
    $reason = null;

    try {
        $arguments = pairing_parse_arguments($argv);
        if (!is_array($arguments)) pairing_refuse('arguments_invalid');

        $ownerRoot = pairing_directory($arguments['ownerRoot']);
        $departmentRoot = pairing_directory($arguments['departmentRoot']);
        if (!is_string($ownerRoot) || !is_string($departmentRoot)) pairing_refuse('unsafe_path');

        $protocolPath = pairing_relative_file($departmentRoot, 'lib/protocol.php', 8388608);
        $adapterPath = pairing_relative_file($departmentRoot, 'adapters/correspondence.php', 8388608);
        if (!is_string($protocolPath) || !is_string($adapterPath)) pairing_refuse('department_source_unavailable');

        $protocolHash = @hash_file('sha256', $protocolPath);
        $adapterHash = @hash_file('sha256', $adapterPath);
        if (!is_string($protocolHash) || !is_string($adapterHash)) pairing_refuse('department_source_unavailable');
        $protocolHash = strtolower($protocolHash);
        $adapterHash = strtolower($adapterHash);
        $report['department']['protocol']['sha256'] = $protocolHash;
        $report['department']['adapter']['sha256'] = $adapterHash;

        $manifest = pairing_manifest($ownerRoot);
        $report['owner']['version'] = $manifest['version'];
        $report['owner']['manifestSha256'] = $manifest['sha256'];

        ob_start();
        try {
            require $protocolPath;
            require $adapterPath;
        } finally {
            $adapterOutput = ob_get_clean();
        }
        $included = [];
        foreach (get_included_files() as $includedPath) {
            $real = realpath($includedPath);
            if (is_string($real)) $included[] = $real;
        }
        $protocolLoaded = in_array($protocolPath, $included, true);
        $adapterLoaded = in_array($adapterPath, $included, true);
        $report['checks']['adapterLoaded'] = $protocolLoaded && $adapterLoaded;
        $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded([$ownerRoot]);
        if ($report['limits']['ownerRuntimeLoaded']) pairing_refuse('owner_runtime_loaded');
        if (!pairing_department_stable($protocolPath, $adapterPath, $protocolHash, $adapterHash)) {
            pairing_refuse('department_source_changed');
        }
        if (($adapterOutput ?? '') !== '' || !$report['checks']['adapterLoaded']) {
            pairing_refuse('department_source_unavailable');
        }
        if (!function_exists('qd_correspondence_source_files')
            || !function_exists('qd_correspondence_manifest_ready')
            || !function_exists('qd_correspondence_current_source_identity')
            || !function_exists('qd_correspondence_runtime_ready')
            || !defined('QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION')) {
            pairing_refuse('department_adapter_contract_missing');
        }

        if (!is_array($manifest['manifest'])
            || ($manifest['manifest']['name'] ?? null) !== 'correspondence'
            || !is_string($manifest['version'])) {
            pairing_refuse('owner_manifest_invalid');
        }
        $version = $manifest['version'];
        $sourceFiles = qd_correspondence_source_files($ownerRoot);
        if (!is_array($sourceFiles)) pairing_refuse('department_adapter_contract_drift');
        $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded([$ownerRoot]);
        if ($report['limits']['ownerRuntimeLoaded']) pairing_refuse('owner_runtime_loaded');

        $expected = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$version] ?? null;
        if (!is_array($expected)) {
            if ($sourceFiles !== []) pairing_refuse('department_adapter_contract_drift');
            $report['checks']['manifestReady'] = qd_correspondence_manifest_ready($ownerRoot);
            $report['checks']['sourceIdentityReady'] = is_array(qd_correspondence_current_source_identity($ownerRoot));
            $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded([$ownerRoot]);
            if (!pairing_department_stable($protocolPath, $adapterPath, $protocolHash, $adapterHash)) {
                pairing_refuse('department_source_changed');
            }
            if ($report['limits']['ownerRuntimeLoaded']) pairing_refuse('owner_runtime_loaded');
            pairing_refuse('owner_version_unsupported');
        }

        if (count($expected) > 64) pairing_refuse('owner_source_limit_exceeded');
        $expectedKeys = array_keys($expected);
        $sourceKeys = array_keys($sourceFiles);
        sort($expectedKeys, SORT_STRING);
        sort($sourceKeys, SORT_STRING);
        if ($expectedKeys !== $sourceKeys) pairing_refuse('department_adapter_contract_drift');

        $sourcePaths = [];
        foreach ($sourceFiles as $relative => $reportedPath) {
            if (!is_string($relative) || !is_string($reportedPath)
                || !is_string($expected[$relative] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/iD', $expected[$relative]) !== 1) {
                pairing_refuse('department_adapter_contract_drift');
            }
            $sourcePath = pairing_relative_file($ownerRoot, $relative);
            $reportedReal = realpath($reportedPath);
            if (!is_string($sourcePath) || !is_string($reportedReal)
                || !pairing_same_path($sourcePath, $reportedReal)) {
                pairing_refuse('unsafe_path');
            }
            $sourcePaths[$relative] = $sourcePath;
        }
        $beforeHashes = pairing_hash_files($sourcePaths, $ownerRoot);
        if (!is_array($beforeHashes)) pairing_refuse('owner_source_limit_exceeded');

        $report['checks']['manifestReady'] = qd_correspondence_manifest_ready($ownerRoot);
        $identity = qd_correspondence_current_source_identity($ownerRoot);
        $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded([$ownerRoot]);
        $afterIdentityHashes = pairing_hash_files($sourcePaths, $ownerRoot);
        $sourceStable = is_array($afterIdentityHashes) && $beforeHashes === $afterIdentityHashes;
        $identityReady = is_array($identity)
            && ($identity['version'] ?? null) === $version
            && ($identity['manifestHash'] ?? null) === $manifest['sha256']
            && is_string($identity['root'] ?? null)
            && pairing_same_path($identity['root'], $ownerRoot);
        $report['checks']['sourceIdentityReady'] = $identityReady;

        $pins = [];
        foreach ($expected as $relative => $expectedHash) {
            $actualHash = $afterIdentityHashes[$relative] ?? null;
            $pins[] = [
                'file' => $relative,
                'expectedSha256' => strtolower($expectedHash),
                'actualSha256' => is_string($actualHash) ? $actualHash : null,
                'matches' => is_string($actualHash) && hash_equals(strtolower($expectedHash), $actualHash),
            ];
        }
        usort($pins, static fn(array $left, array $right): int => strcmp($left['file'], $right['file']));
        $report['owner']['selectedClosurePins'] = $pins;

        if (!pairing_department_stable($protocolPath, $adapterPath, $protocolHash, $adapterHash)) {
            pairing_refuse('department_source_changed');
        }
        if (!$sourceStable || !$report['checks']['manifestReady']
            || count(array_filter($pins, static fn(array $pin): bool => !$pin['matches'])) > 0) {
            pairing_refuse('owner_source_drift');
        }
        if ($report['limits']['ownerRuntimeLoaded']) pairing_refuse('owner_runtime_loaded');
        if (!$identityReady) pairing_refuse('owner_identity_unavailable');

        $tempBase = realpath(sys_get_temp_dir());
        if (!is_string($tempBase) || pairing_path_has_link(sys_get_temp_dir()) || !is_dir($tempBase)) {
            pairing_refuse('temporary_staging_failed');
        }
        $stage = pairing_create_stage($tempBase, $sourcePaths, $beforeHashes);
        if (is_array($stage) && is_string($stage['tempRoot'] ?? null)) $tempRoot = $stage['tempRoot'];
        if (!is_array($stage) || ($stage['failed'] ?? true)) pairing_refuse('temporary_staging_failed');

        $GLOBALS['pairing_runtime_core_root'] = $stage['coreRoot'];
        $report['checks']['runtimeReady'] = qd_correspondence_runtime_ready();
        $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded([$ownerRoot, $stage['extensionRoot']]);

        $stagePaths = [];
        foreach ($sourcePaths as $relative => $_sourcePath) {
            $stagePath = pairing_relative_file($stage['extensionRoot'], $relative);
            if (!is_string($stagePath)) {
                $stagePaths = [];
                break;
            }
            $stagePaths[$relative] = $stagePath;
        }
        $stagedHashes = $stagePaths === [] ? null : pairing_hash_files($stagePaths, $stage['extensionRoot']);
        $afterRuntimeHashes = pairing_hash_files($sourcePaths, $ownerRoot);
        $report['checks']['stagedClosureMatches'] = is_array($stagedHashes)
            && $stagedHashes === $beforeHashes;
        $sourceStable = $sourceStable && is_array($afterRuntimeHashes) && $afterRuntimeHashes === $beforeHashes;

        if (!pairing_department_stable($protocolPath, $adapterPath, $protocolHash, $adapterHash)) {
            pairing_refuse('department_source_changed');
        }
        if (!$sourceStable) pairing_refuse('owner_source_drift');
        if (!$report['checks']['stagedClosureMatches']) pairing_refuse('temporary_staging_failed');
        if ($report['limits']['ownerRuntimeLoaded']) pairing_refuse('owner_runtime_loaded');
        if (!$report['checks']['runtimeReady']) pairing_refuse('runtime_not_ready');

        $report['ready'] = true;
    } catch (PairingRefusal $failure) {
        $ownerRoots = [];
        if (isset($ownerRoot)) $ownerRoots[] = $ownerRoot;
        if (isset($stage['extensionRoot'])) $ownerRoots[] = $stage['extensionRoot'];
        if ($ownerRoots !== []) $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded($ownerRoots);
        $reason = $report['limits']['ownerRuntimeLoaded'] ? 'owner_runtime_loaded' : $failure->reason;
    } catch (Throwable) {
        $ownerRoots = [];
        if (isset($ownerRoot)) $ownerRoots[] = $ownerRoot;
        if (isset($stage['extensionRoot'])) $ownerRoots[] = $stage['extensionRoot'];
        if ($ownerRoots !== []) $report['limits']['ownerRuntimeLoaded'] = pairing_owner_runtime_loaded($ownerRoots);
        $reason = $report['limits']['ownerRuntimeLoaded'] ? 'owner_runtime_loaded' : 'adapter_evaluation_failed';
    } finally {
        if (is_string($tempRoot)) {
            $realTempBase = is_string($tempBase) ? $tempBase : null;
            $realTempRoot = realpath($tempRoot);
            $cleanupOk = is_string($realTempBase) && is_string($realTempRoot)
                && !is_link($tempRoot)
                && !pairing_path_has_link($tempRoot)
                && pairing_same_path(dirname($realTempRoot), $realTempBase)
                && str_starts_with(basename($realTempRoot), 'department-owner-pairing-')
                && pairing_remove_tree($realTempRoot, $realTempRoot);
            if (!$cleanupOk) {
                $report['ready'] = false;
                $reason ??= 'temporary_cleanup_failed';
            }
        }
    }

    if (!$report['ready'] && $reason === null) $reason = 'runtime_not_ready';
    $report['errorReason'] = $report['ready'] ? null : $reason;
    return $report;
}

$report = pairing_check($argv);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($report['ready'] ? 0 : 1);
