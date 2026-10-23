<?php
declare(strict_types=1);

const OVERLAY_TEST_FILES = [
    'installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php',
    'reflection.php', 'scope.php', 'state.php',
];

function overlay_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function overlay_test_mkdir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create isolated fixture directory.');
    }
}

function overlay_test_copy_files(string $source, string $destination, array $names): void
{
    overlay_test_mkdir($destination);
    foreach ($names as $name) {
        $from = $source . DIRECTORY_SEPARATOR . $name;
        $to = $destination . DIRECTORY_SEPARATOR . $name;
        overlay_test_expect(is_file($from) && !is_link($from) && @copy($from, $to), 'Pinned fixture file missing: ' . $name);
    }
}

function overlay_test_copy_tree(string $source, string $destination): void
{
    if (!is_dir($source) || is_link($source)) {
        throw new RuntimeException('Pinned fixture source directory is missing.');
    }
    overlay_test_copy_files($source, $destination, OVERLAY_TEST_FILES);
}

function overlay_test_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $item) {
        if (!$item->isDot()) {
            overlay_test_remove_tree($item->getPathname());
        }
    }
    @rmdir($path);
}

function overlay_test_owner_hashes(string $ownerRoot): array
{
    $hashes = [];
    foreach (OVERLAY_TEST_FILES as $name) {
        $hash = @hash_file('sha256', $ownerRoot . DIRECTORY_SEPARATOR . $name);
        overlay_test_expect(is_string($hash), 'Could not hash fixture owner file: ' . $name);
        $hashes[$name] = $hash;
    }
    return $hashes;
}

function overlay_test_lock_is_free(string $privateRoot): bool
{
    $handle = @fopen($privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay.lock.php', 'c+b');
    if (!is_resource($handle)) {
        return false;
    }
    $acquired = @flock($handle, LOCK_EX | LOCK_NB);
    if ($acquired) {
        @flock($handle, LOCK_UN);
    }
    fclose($handle);
    return $acquired;
}

function overlay_test_stub_managed_path(string $path, string $privateRoot, bool $mustExist): ?string
{
    if (is_link($privateRoot) || is_link($path) || !str_ends_with(strtolower(basename($path)), '.php')) {
        return null;
    }
    $realRoot = realpath($privateRoot);
    $realParent = realpath(dirname($path));
    if (!is_string($realRoot) || !is_string($realParent) || !is_dir($realParent)) {
        return null;
    }
    $candidate = $realParent . DIRECTORY_SEPARATOR . basename($path);
    $rootKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($realRoot) : $realRoot;
    $candidateKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($candidate) : $candidate;
    if (!str_starts_with($candidateKey, rtrim($rootKey, '\\/') . DIRECTORY_SEPARATOR)) {
        return null;
    }
    if ($mustExist && !is_file($candidate)) {
        return null;
    }
    return $candidate;
}

function overlay_test_owner_manifest(string $ownerRoot): void
{
    $manifest = json_decode((string)@file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json'), true);
    overlay_test_expect(is_array($manifest)
        && ($manifest['name'] ?? null) === 'private_conversation'
        && ($manifest['version'] ?? null) === '0.1.23'
        && ($manifest['schema_version'] ?? null) === 2
        && ($manifest['git_repo'] ?? null) === 'Francisco-boop-001/CHIM-PrivateConversation',
        'Stock owner manifest does not match pinned PCV 0.1.23.');
}

function overlay_test_failure_child(string $fixtureRoot, string $ownerBase): void
{
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $GLOBALS['overlay_test_lock_path'] = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay.lock.php';
    $GLOBALS['overlay_test_injected'] = false;
    $GLOBALS['overlay_test_lock_violation'] = false;

    define('QD_GUARDED_JSON_PREFIX', "<?php http_response_code(404); exit; __halt_compiler();\n");
    function qd_path_key(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('/', '\\', $path)) : $path;
    }
    function qd_managed_file_path(string $path, string $privateRoot, bool $mustExist): ?string
    {
        return overlay_test_stub_managed_path($path, $privateRoot, $mustExist);
    }
    function qd_atomic_write(string $path, string $content, bool $guarded = false): void
    {
        $isOwnerSource = str_contains(str_replace('\\', '/', $path), '/ext/private_conversation/');
        if ($isOwnerSource && basename($path) === 'scope.php' && !$GLOBALS['overlay_test_injected']) {
            $GLOBALS['overlay_test_injected'] = true;
            throw new RuntimeException('Injected write failure for rollback regression.');
        }
        if ($isOwnerSource && basename($path) === 'preprocessing.php' && $GLOBALS['overlay_test_injected']) {
            $probe = @fopen($GLOBALS['overlay_test_lock_path'], 'c+b');
            overlay_test_expect(is_resource($probe), 'Could not probe the held install lock.');
            $acquired = @flock($probe, LOCK_EX | LOCK_NB);
            if ($acquired) {
                @flock($probe, LOCK_UN);
                $GLOBALS['overlay_test_lock_violation'] = true;
            }
            fclose($probe);
            overlay_test_expect(!$acquired, 'Rollback ran after the install lock was released.');
        }
        $bytes = $guarded ? QD_GUARDED_JSON_PREFIX . $content : $content;
        overlay_test_expect(@file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 'Injected writer could not persist fixture bytes.');
    }

    $helper = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions'
        . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    require $helper;
    $privateRoot = realpath($privateRoot) ?: $privateRoot;
    $coreRoot = realpath($fixtureRoot);
    $result = qd_pcv_overlay_apply([
        'chimRoot' => $coreRoot,
        'privateRoot' => $privateRoot,
        'webRoot' => $coreRoot,
        'pcvRoot' => $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation',
    ]);
    $ownerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $expectedBase = [];
    foreach (['preprocessing.php', 'scope.php', 'state.php'] as $name) {
        $expectedBase[$name] = hash_file('sha256', $ownerBase . DIRECTORY_SEPARATOR . $name);
    }
    $actual = overlay_test_owner_hashes($ownerRoot);
    foreach ($expectedBase as $name => $hash) {
        overlay_test_expect($actual[$name] === $hash, 'Injected failure did not restore original bytes for ' . $name . '.');
    }
    overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'write_failed'], 'Injected failure returned an unexpected result: ' . json_encode($result));
    overlay_test_expect($GLOBALS['overlay_test_injected'] && !$GLOBALS['overlay_test_lock_violation'], 'Rollback lock invariant was not observed.');
    overlay_test_expect(overlay_test_lock_is_free($privateRoot), 'Install lock remained held after rollback returned.');
}

function overlay_test_case(string $case, string $stageSource, string $ownerBase, string $manifestPath): void
{
    $repoRoot = dirname(__DIR__, 3);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-overlay-' . bin2hex(random_bytes(8));
    $pluginRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $helperPath = $pluginRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';

    try {
        overlay_test_mkdir(dirname($helperPath));
        $productionHelper = $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
        overlay_test_expect(@copy($productionHelper, $helperPath), 'Could not copy production helper into fixture.');
        overlay_test_expect(hash_file('sha256', $helperPath) === hash_file('sha256', $productionHelper), 'Fixture helper differs from production.');
        overlay_test_mkdir($fixtureRoot . DIRECTORY_SEPARATOR . 'data');
        overlay_test_mkdir($privateRoot);
        overlay_test_copy_files($ownerBase, $ownerRoot, OVERLAY_TEST_FILES);
        overlay_test_owner_manifest($ownerRoot);
        if ($case === 'already-enabled') {
            foreach (OVERLAY_TEST_FILES as $name) {
                overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . $name, $ownerRoot . DIRECTORY_SEPARATOR . $name), 'Could not create already-enabled owner fixture.');
            }
        }
        if ($case === 'drift') {
            overlay_test_expect(@file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'scope.php', "\nfixture drift\n", FILE_APPEND) !== false, 'Could not inject owner drift.');
        }
        if ($case !== 'missing-stage') {
            $stageRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
            overlay_test_copy_tree($stageSource, $stageRoot);
            overlay_test_expect(@copy($manifestPath, $stageRoot . DIRECTORY_SEPARATOR . 'source-manifest.json'), 'Could not stage pinned manifest.');
        }
        $initialHashes = overlay_test_owner_hashes($ownerRoot);
        $coreRoot = realpath($fixtureRoot);
        if ($case === 'rollback-failure') {
            overlay_test_failure_child($fixtureRoot, $ownerBase);
        } else {
            require_once $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth.php';
            require $helperPath;
            $result = qd_pcv_overlay_apply([
                'chimRoot' => $coreRoot,
                'privateRoot' => realpath($privateRoot) ?: $privateRoot,
                'webRoot' => $coreRoot,
                'pcvRoot' => $ownerRoot,
            ]);
            if ($case === 'normal') {
                overlay_test_expect($result === ['state' => 'enabled', 'reason' => null], 'Stock owner install did not enable the overlay.');
                overlay_test_expect(overlay_test_lock_is_free($privateRoot), 'Install lock remained held after successful apply.');
                $expectedPost = [];
                foreach (OVERLAY_TEST_FILES as $name) {
                    $expectedPost[$name] = hash_file('sha256', $stageSource . DIRECTORY_SEPARATOR . $name);
                }
                overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $expectedPost, 'Successful apply did not match all seven pinned postimages.');
                $backup = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
                $backupBytes = @file_get_contents($backup);
                overlay_test_expect(is_string($backupBytes) && str_starts_with($backupBytes, QD_GUARDED_JSON_PREFIX), 'Original bytes were not stored in guarded private backup.');
                $backupHash = hash('sha256', $backupBytes);
                $again = qd_pcv_overlay_apply([
                    'chimRoot' => $coreRoot,
                    'privateRoot' => realpath($privateRoot) ?: $privateRoot,
                    'webRoot' => $coreRoot,
                    'pcvRoot' => $ownerRoot,
                ]);
                overlay_test_expect($again === ['state' => 'already_enabled', 'reason' => null], 'Second apply was not idempotent.');
                overlay_test_expect(overlay_test_lock_is_free($privateRoot), 'Install lock remained held after idempotent retry.');
                overlay_test_expect(hash_file('sha256', $backup) === $backupHash, 'Idempotent apply changed the original backup.');
            } elseif ($case === 'already-enabled') {
                overlay_test_expect($result === ['state' => 'already_enabled', 'reason' => null], 'Already-enabled install was not recognized.');
                overlay_test_expect(!file_exists($privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php'), 'Already-enabled install unexpectedly created a backup.');
            } elseif ($case === 'drift') {
                overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'owner_incompatible'], 'Owner drift was not refused.');
                overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialHashes, 'Owner drift refusal changed a target file.');
            } elseif ($case === 'missing-stage') {
                overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'staged_source_missing'], 'Missing stage was not reported.');
                overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialHashes, 'Missing stage changed a target file.');
            } else {
                throw new RuntimeException('Unknown overlay test case.');
            }
        }
        echo json_encode(['case' => $case, 'passed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        overlay_test_remove_tree($fixtureRoot);
    }
}

$repoRoot = dirname(__DIR__, 3);
if (($argv[1] ?? '') === '--case') {
    $case = $argv[2] ?? '';
    $stageSource = realpath($argv[3] ?? '');
    $ownerBase = realpath($argv[4] ?? '');
    $manifestPath = realpath($argv[5] ?? '');
    if (!in_array($case, ['normal', 'already-enabled', 'drift', 'missing-stage', 'rollback-failure'], true)
        || !is_string($stageSource) || !is_dir($stageSource)
        || !is_string($ownerBase) || !is_dir($ownerBase)
        || !is_string($manifestPath) || !is_file($manifestPath)) {
        fwrite(STDERR, "Usage: php -n overlay.php --case <case> <postimage-source-dir> <stock-owner-server-dir> <source-manifest.json>\n");
        exit(2);
    }
    try {
        overlay_test_case($case, $stageSource, $ownerBase, $manifestPath);
    } catch (Throwable $failure) {
        fwrite(STDERR, json_encode(['case' => $case, 'passed' => false, 'error' => $failure->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
    exit(0);
}

$stageSource = $argv[1] ?? '';
$ownerBase = $argv[2] ?? '';
$manifestPath = $argv[3] ?? '';
if ($stageSource === '' || $ownerBase === '' || $manifestPath === '') {
    fwrite(STDERR, "Usage: php -n overlay.php <postimage-source-dir> <stock-owner-server-dir> <source-manifest.json>\n");
    exit(2);
}
$failures = [];
foreach (['normal', 'already-enabled', 'drift', 'missing-stage', 'rollback-failure'] as $case) {
    $command = [PHP_BINARY, '-n', __FILE__, '--case', $case, $stageSource, $ownerBase, $manifestPath];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
    overlay_test_expect(is_resource($process), 'Could not start isolated fixture process.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    echo trim((string)$stdout) . (trim((string)$stderr) !== '' ? "\n" . trim((string)$stderr) : '') . PHP_EOL;
    if ($exitCode !== 0) {
        $failures[] = $case;
    }
}
exit($failures === [] ? 0 : 1);
