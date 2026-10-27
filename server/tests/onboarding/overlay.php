<?php
declare(strict_types=1);

const OVERLAY_TEST_FILES = [
    'installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php',
    'reflection.php', 'scope.php', 'state.php',
];
const OVERLAY_TEST_LEGACY_PINS = [
    'preprocessing.php' => '5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c',
    'scope.php' => '763ff62b129086f7c3d91fbccbb80e8db0ec71b04d11fc150c4881420bbe6992',
    'state.php' => '142f5a026740dfb629af925005c7e931396aa3dcde0d534002fb42b545c5664d',
];
const OVERLAY_TEST_LEGACY_POSTS = [
    'preprocessing.php' => '01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950',
    'scope.php' => 'aa800deb4a34e0a77663e938b80a11d748978fc1b420a708c1c9125fce9bf398',
    'state.php' => '3fbc48a0f25178011f0b838cc01d3664932822695d7d39c35d49cbc11fa057c1',
];
const OVERLAY_TEST_LEGACY_VERSION = '0.1.23';
const OVERLAY_TEST_LEGACY_COMMIT = '77265202d7f4a47b88e1eb88131b21f4b0752020';
const OVERLAY_TEST_ARCHIVE = 'pcv-overlay-backup-v0.1.23.php';

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

function overlay_test_copy_tree(string $source, string $destination, array $stageMap): void
{
    if (!is_dir($source) || is_link($source)) {
        throw new RuntimeException('Pinned fixture source directory is missing.');
    }
    foreach (OVERLAY_TEST_FILES as $name) {
        $stagedName = $stageMap[$name] ?? null;
        overlay_test_expect(is_string($stagedName) && $stagedName !== '' && basename($stagedName) === $stagedName,
            'Pinned stage mapping missing for owner file: ' . $name);
        $from = $source . DIRECTORY_SEPARATOR . $name;
        $to = $destination . DIRECTORY_SEPARATOR . $stagedName;
        overlay_test_mkdir($destination);
        overlay_test_expect(is_file($from) && !is_link($from) && @copy($from, $to), 'Pinned fixture file missing: ' . $name);
    }
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

function overlay_test_read_manifest(string $manifestPath): array
{
    $manifest = json_decode((string)@file_get_contents($manifestPath), true);
    overlay_test_expect(is_array($manifest) && ($manifest['schemaVersion'] ?? null) === 1
        && is_array($manifest['upstream'] ?? null) && ($manifest['upstream']['version'] ?? null) === '0.1.24'
        && is_array($manifest['files'] ?? null) && array_is_list($manifest['files']),
        'Staged owner manifest does not identify PCV 0.1.24.');
    return $manifest;
}

function overlay_test_stage_map(array $manifest): array
{
    $expected = [
        'installation_guard.php' => 'installation_guard.php',
        'log.php' => 'log.php',
        'manifest.json' => 'manifest.json',
        'preprocessing.php' => 'preprocessing.php.template',
        'reflection.php' => 'reflection.php',
        'scope.php' => 'scope.php',
        'state.php' => 'state.php',
    ];
    overlay_test_expect(count($manifest['files']) === count($expected), 'Staged source has the wrong owner file count.');
    $actual = [];
    foreach ($manifest['files'] as $entry) {
        overlay_test_expect(is_array($entry) && array_keys($entry) === [
            'path', 'stagedPath', 'baseSha256', 'postimageSha256', 'modified',
        ] && is_string($entry['path'] ?? null) && is_string($entry['stagedPath'] ?? null),
            'Staged source manifest has an invalid transport mapping.');
        $actual[$entry['path']] = $entry['stagedPath'];
    }
    overlay_test_expect($actual === $expected, 'Staged source has an unexpected owner-to-stage mapping.');
    return $actual;
}

function overlay_test_owner_manifest(string $ownerRoot): void
{
    $manifest = json_decode((string)@file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json'), true);
    overlay_test_expect(is_array($manifest)
        && ($manifest['name'] ?? null) === 'private_conversation'
        && ($manifest['version'] ?? null) === '0.1.24'
        && ($manifest['schema_version'] ?? null) === 2
        && ($manifest['git_repo'] ?? null) === 'Francisco-boop-001/CHIM-PrivateConversation',
        'Stock owner manifest does not match pinned PCV 0.1.24.');
}

function overlay_test_current_pins(array $manifest): array
{
    $pins = [];
    foreach ($manifest['files'] as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)
            || !is_string($entry['baseSha256'] ?? null) || !is_string($entry['postimageSha256'] ?? null)) {
            throw new RuntimeException('Staged source manifest contains a malformed pin.');
        }
        if (!hash_equals($entry['baseSha256'], $entry['postimageSha256'])) {
            $pins[$entry['path']] = $entry['baseSha256'];
        }
    }
    overlay_test_expect(array_keys($pins) === ['preprocessing.php', 'scope.php', 'state.php'],
        '0.1.24 source manifest has an unexpected modified-file set.');
    return $pins;
}

function overlay_test_make_backup(
    string $ownerRoot,
    string $sourceRoot,
    string $ownerVersion,
    string $ownerCommit,
    array $filePins,
    ?string $chimRootOverride = null
): string {
    $chimRoot = realpath(dirname($ownerRoot, 2));
    $pcvRoot = realpath($ownerRoot);
    overlay_test_expect(is_string($chimRoot) && is_string($pcvRoot), 'Fixture backup roots are unavailable.');
    $files = [];
    foreach ($filePins as $relative => $expectedHash) {
        $bytes = @file_get_contents($sourceRoot . DIRECTORY_SEPARATOR . $relative);
        overlay_test_expect(is_string($bytes) && hash_equals($expectedHash, hash('sha256', $bytes)),
            'Backup fixture source does not match its exact base pin: ' . $relative);
        $files[$relative] = ['sha256' => $expectedHash, 'bytesBase64' => base64_encode($bytes)];
    }
    $state = [
        'schemaVersion' => 1,
        'chimRoot' => $chimRootOverride ?? $chimRoot,
        'pcvRoot' => $pcvRoot,
        'ownerVersion' => $ownerVersion,
        'ownerCommit' => $ownerCommit,
        'files' => $files,
    ];
    return "<?php http_response_code(404); exit; __halt_compiler();\n"
        . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function overlay_test_legacy_backup(string $ownerRoot, string $legacyOwnerRoot, ?string $chimOverride = null): string
{
    $manifest = json_decode((string)@file_get_contents($legacyOwnerRoot . DIRECTORY_SEPARATOR . 'manifest.json'), true);
    overlay_test_expect(is_array($manifest) && ($manifest['version'] ?? null) === OVERLAY_TEST_LEGACY_VERSION
        && ($manifest['schema_version'] ?? null) === 2
        && ($manifest['git_repo'] ?? null) === 'Francisco-boop-001/CHIM-PrivateConversation',
        'Explicit legacy-owner-root is not the pinned 0.1.23 owner tree.');
    foreach (OVERLAY_TEST_LEGACY_PINS as $name => $expected) {
        overlay_test_expect(hash_file('sha256', $legacyOwnerRoot . DIRECTORY_SEPARATOR . $name) === $expected,
            'Explicit legacy-owner-root differs from the exact 0.1.23 base pin: ' . $name);
    }
    return overlay_test_make_backup($ownerRoot, $legacyOwnerRoot, OVERLAY_TEST_LEGACY_VERSION,
        OVERLAY_TEST_LEGACY_COMMIT, OVERLAY_TEST_LEGACY_PINS, $chimOverride);
}

function overlay_test_write(string $path, string $bytes): void
{
    overlay_test_expect(@file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes),
        'Could not write isolated backup fixture.');
}

function overlay_test_apply(string $fixtureRoot, string $ownerRoot, string $privateRoot): array
{
    return qd_pcv_overlay_apply([
        'chimRoot' => realpath($fixtureRoot) ?: $fixtureRoot,
        'privateRoot' => realpath($privateRoot) ?: $privateRoot,
        'webRoot' => realpath($fixtureRoot) ?: $fixtureRoot,
        'pcvRoot' => $ownerRoot,
    ]);
}

function overlay_test_failure_child(
    string $fixtureRoot,
    string $ownerBase,
    string $stageSource,
    string $legacyOwnerRoot,
    string $manifestPath,
    bool $failActiveRestore = false
): void {
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $GLOBALS['overlay_test_lock_path'] = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay.lock.php';
    $GLOBALS['overlay_test_target'] = 'state.php';
    $GLOBALS['overlay_test_injected'] = false;
    $GLOBALS['overlay_test_lock_checked'] = false;
    $GLOBALS['overlay_test_lock_violation'] = false;
    $GLOBALS['overlay_test_fail_active_restore'] = $failActiveRestore;
    $GLOBALS['overlay_test_active_restore_failed'] = false;

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
        $bytes = $guarded ? QD_GUARDED_JSON_PREFIX . $content : $content;
        if (!$isOwnerSource && basename($path) === 'pcv-overlay-backup.php'
            && $GLOBALS['overlay_test_fail_active_restore'] && !$guarded
            && str_starts_with($content, QD_GUARDED_JSON_PREFIX)) {
            $GLOBALS['overlay_test_fail_active_restore'] = false;
            $GLOBALS['overlay_test_active_restore_failed'] = true;
            throw new RuntimeException('Injected active backup restore failure.');
        }
        if ($isOwnerSource && basename($path) === $GLOBALS['overlay_test_target'] && !$GLOBALS['overlay_test_injected']) {
            overlay_test_expect(@file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes),
                'Could not inject the after-write failure.');
            $GLOBALS['overlay_test_injected'] = true;
            throw new RuntimeException('Injected owner write failure for backup rollback regression.');
        }
        if ($isOwnerSource && $GLOBALS['overlay_test_injected'] && !$GLOBALS['overlay_test_lock_checked']) {
            $GLOBALS['overlay_test_lock_checked'] = true;
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
        overlay_test_expect(@file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes),
            'Injected writer could not persist fixture bytes.');
    }

    $helper = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions'
        . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    require $helper;
    $manifest = overlay_test_read_manifest($manifestPath);
    $pins = overlay_test_current_pins($manifest);
    $privateRoot = realpath($privateRoot) ?: $privateRoot;
    $coreRoot = realpath($fixtureRoot);
    $ownerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $initialOwner = overlay_test_owner_hashes($ownerRoot);
    $activePath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
    $archivePath = $privateRoot . DIRECTORY_SEPARATOR . OVERLAY_TEST_ARCHIVE;
    $legacyHookPath = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions'
        . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay' . DIRECTORY_SEPARATOR . 'preprocessing.php';
    $legacyHookBefore = @file_get_contents($legacyHookPath);
    overlay_test_expect(is_string($legacyHookBefore)
        && hash_equals('01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950', hash('sha256', $legacyHookBefore)),
        'Rollback fixture did not contain the exact known stale active hook.');
    $legacyRaw = overlay_test_legacy_backup($ownerRoot, $legacyOwnerRoot);
    overlay_test_write($activePath, $legacyRaw);
    $activeBefore = @file_get_contents($activePath);
    overlay_test_expect(is_string($activeBefore), 'Could not snapshot pre-upgrade active backup bytes.');

    $result = overlay_test_apply($fixtureRoot, $ownerRoot, $privateRoot);
    overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
        'Failed migration did not restore the exact pre-attempt owner bytes.');
    overlay_test_expect(hash_equals($legacyHookBefore,
        (string)@file_get_contents($legacyHookPath)),
        'Failed migration did not preserve the exact stale active hook for rollback.');
    if ($failActiveRestore) {
        overlay_test_expect($GLOBALS['overlay_test_active_restore_failed'], 'Active backup restore failure was not injected.');
        overlay_test_expect(is_file($archivePath) && hash_equals($legacyRaw, (string)@file_get_contents($archivePath)),
            'Failed active restore destroyed the only known legacy backup copy.');
        $active = qd_pcv_overlay_backup_state($activePath, $coreRoot, qd_pcv_overlay_pins());
        overlay_test_expect(is_array($active), 'Failed active restore left the current backup unreadable.');
    } else {
        overlay_test_expect(is_string($activeBefore) && hash_equals($activeBefore, (string)@file_get_contents($activePath)),
            'Failed migration did not restore the exact legacy active backup bytes.');
        overlay_test_expect(!file_exists($archivePath) && !is_link($archivePath),
            'Failed migration left an archive created by the failed attempt.');
    }
    overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'write_failed'],
        'Injected migration failure returned an unexpected result: ' . json_encode($result));
    overlay_test_expect($GLOBALS['overlay_test_injected'] && !$GLOBALS['overlay_test_lock_violation']
        && $GLOBALS['overlay_test_lock_checked'], 'Rollback lock invariant was not observed.');
    overlay_test_expect(overlay_test_lock_is_free($privateRoot), 'Install lock remained held after rollback returned.');
}

function overlay_test_extract_function(string $source, string $name): string
{
    $tokens = token_get_all($source);
    for ($index = 0; $index < count($tokens); $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }
        $nameIndex = $index + 1;
        while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex])
            && in_array($tokens[$nameIndex][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $nameIndex++;
        }
        if (isset($tokens[$nameIndex]) && is_string($tokens[$nameIndex]) && $tokens[$nameIndex] === '&') {
            $nameIndex++;
        }
        if (!is_array($tokens[$nameIndex] ?? null) || $tokens[$nameIndex][0] !== T_STRING
            || $tokens[$nameIndex][1] !== $name) {
            continue;
        }

        $parts = [];
        $depth = 0;
        $started = false;
        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;
            $parts[] = $text;
            if (!is_array($token) && $text === '{') {
                $depth++;
                $started = true;
            } elseif (!is_array($token) && $text === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    return implode('', $parts);
                }
            }
        }
        throw new RuntimeException('Captured core loader function is incomplete.');
    }
    throw new RuntimeException('Captured core source does not declare the pinned recursive loader.');
}

function overlay_test_normalize_path(string $path): string
{
    $canonical = realpath($path);
    return str_replace('\\', '/', is_string($canonical) ? $canonical : $path);
}

function overlay_test_loader_child(string $fixtureRoot, string $capturedCore, string $tracePath): void
{
    overlay_test_expect(hash_file('sha256', $capturedCore) === '1111b724c65b017dbbc7baec6dc32a0cee0277222dc6f3bdb6b7a7414e547552',
        'Captured core source differs from the pinned offline CHIM file.');
    $source = @file_get_contents($capturedCore);
    overlay_test_expect(is_string($source), 'Could not read pinned captured core source.');
    $loader = overlay_test_extract_function($source, 'requireFilesRecursively');
    overlay_test_expect(str_contains($loader, 'scandir($dir)') && str_contains($loader, '$file === $name')
        && str_contains($loader, 'require_once($path)'), 'Extracted core loader does not match the exact-basename recursive contract.');

    if (!function_exists('pcv_current_playthrough_key')) {
        function pcv_current_playthrough_key(bool $refresh = false): ?string
        {
            return 'offline-solo-fixture';
        }
    }
    if (!function_exists('pcv_current_player_name')) {
        function pcv_current_player_name(): ?string
        {
            return 'Dragonborn';
        }
    }
    if (!function_exists('pcv_read')) {
        function pcv_read(string $key, ?string $stateDirectory = null): array
        {
            return $GLOBALS['overlay_test_state'];
        }
    }
    if (!function_exists('pcv_capture_presence_snapshot')) {
        function pcv_capture_presence_snapshot(?string $key, $raw, ?string $stateDirectory = null, ?int $requestTimestamp = null): array
        {
            return ['status' => 'ready', 'actors' => [['name' => 'Aela']]];
        }
    }
    if (!function_exists('pcv_read_npc_catalog')) {
        function pcv_read_npc_catalog(): array
        {
            return [['id' => '303', 'npc_name' => 'Aela', 'profile_id' => '93']];
        }
    }
    if (!function_exists('pcv_read_active_scene_npcs')) {
        function pcv_read_active_scene_npcs(?string $key, array $catalogRows, ?string $playerName, array $actorIds,
            ?string $stateDirectory = null, ?int $now = null): array
        {
            return ['known_npcs' => ['303' => 'Aela'], 'missing' => [], 'error' => false];
        }
    }
    if (!function_exists('pcv_begin_request')) {
        function pcv_begin_request(string $key, bool $eligible, ?string $stateDirectory = null, ?array $eligibleNpcMap = null,
            ?array $activeEligibleNpcMap = null, ?string $activePresenceCheck = null, ?array $freeCandidateOrder = null,
            bool $allowActiveDrops = true, ?callable $companionPrecommit = null): array
        {
            return $GLOBALS['overlay_test_state'];
        }
    }

    $scope = [
        'enabled' => true,
        'scene_mode' => 'solo',
        'actor_a' => '303',
        'actor_b' => null,
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $GLOBALS['overlay_test_state'] = [
        'status' => 'active',
        'config_id' => 'offline-solo-config',
        'scope' => $scope,
        'profile_id_a' => 93,
        'actor_a_id' => '303',
        'actor_b_id' => null,
        'profiles' => ['Aela' => 93],
    ];
    $GLOBALS['CHIM_EXECUTION_MODE'] = 'STANDARD';
    $GLOBALS['requestRoutingSnapshot'] = [];
    $GLOBALS['gameRequest'] = ['inputtext', 'fixture', null, 'Dragonborn: I wait quietly.', ['Aela [RefID: 0000012C]']];
    http_response_code(200);

    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    ini_set('log_errors', '1');
    ini_set('error_log', $fixtureRoot . DIRECTORY_SEPARATOR . 'pcv-error.log');
    require_once $ownerRoot . DIRECTORY_SEPARATOR . 'log.php';
    register_shutdown_function(static function () use ($tracePath): void {
        $hookPaths = array_values(array_filter(get_included_files(), static fn(string $path): bool => basename($path) === 'preprocessing.php'));
        $request = $GLOBALS['gameRequest'] ?? [];
        $scope = $GLOBALS['PCV_REQUEST_SCOPE'] ?? [];
        $logRequest = pcv_log_request_context();
        $trace = [
            'httpStatus' => http_response_code(),
            'hookCount' => count($hookPaths),
            'hookPaths' => array_map('overlay_test_normalize_path', $hookPaths),
            'requestType' => is_array($request) ? ($request[0] ?? null) : null,
            'dialogue' => is_array($request) && is_string($request[3] ?? null) ? $request[3] : null,
            'route' => is_array($scope) ? ($scope['route'] ?? null) : null,
            'originRequestType' => is_array($scope) ? ($scope['origin_request_type'] ?? null) : null,
            'originDialogue' => is_array($scope) ? ($scope['origin_dialogue'] ?? null) : null,
            'terminalReason' => is_array($logRequest['terminal'] ?? null) ? ($logRequest['terminal']['reason'] ?? null) : null,
        ];
        file_put_contents($tracePath, json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    });

    eval($loader);
    requireFilesRecursively($fixtureRoot . DIRECTORY_SEPARATOR . 'ext', 'preprocessing.php');
}

function overlay_test_capture_loader(string $fixtureRoot, string $capturedCore, string $tracePath): array
{
    $command = [PHP_BINARY, '-n', __FILE__, '--loader-run', '1', '--fixture-root', $fixtureRoot,
        '--captured-core', $capturedCore, '--trace-output', $tracePath];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
    overlay_test_expect(is_resource($process), 'Could not start isolated captured-loader process.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $trace = json_decode((string)@file_get_contents($tracePath), true);
    overlay_test_expect(is_array($trace), 'Captured loader process did not produce its shutdown trace (exit '
        . $exitCode . '; stdout: ' . trim((string)$stdout) . '; stderr: ' . trim((string)$stderr) . ').');
    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'trace' => $trace];
}

function overlay_test_full_dir_swap(string $fixtureRoot, string $stageSource, string $manifestPath, array $stageMap): void
{
    $swapRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'dwpkg-full-dir-swap';
    $oldPlugin = $swapRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $newPlugin = $swapRoot . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $oldStage = $oldPlugin . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
    $newStage = $newPlugin . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
    $oldBackup = $swapRoot . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    overlay_test_mkdir($oldStage);
    overlay_test_mkdir(dirname($newStage));
    overlay_test_copy_tree($stageSource, $newStage, $stageMap);
    overlay_test_expect(@copy($manifestPath, $newStage . DIRECTORY_SEPARATOR . 'source-manifest.json'),
        'Could not stage the full-directory swap manifest.');
    $stale = $oldStage . DIRECTORY_SEPARATOR . 'preprocessing.php';
    overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . 'preprocessing.php', $stale),
        'Could not create the old package tree for full-directory swap.');
    overlay_test_expect(!file_exists($newStage . DIRECTORY_SEPARATOR . 'preprocessing.php')
        && is_file($newStage . DIRECTORY_SEPARATOR . 'preprocessing.php.template'),
        'New DWPkg candidate did not carry the preprocessing source as an inert template.');
    overlay_test_mkdir(dirname($oldBackup));
    overlay_test_expect(@rename($oldPlugin, $oldBackup), 'Could not snapshot old plugin directory for swap.');
    overlay_test_expect(@rename($newPlugin, $oldPlugin), 'Could not install the staged full plugin directory.');
    overlay_test_expect(!file_exists($oldStage . DIRECTORY_SEPARATOR . 'preprocessing.php')
        && !is_link($oldStage . DIRECTORY_SEPARATOR . 'preprocessing.php')
        && is_file($oldStage . DIRECTORY_SEPARATOR . 'preprocessing.php.template')
        && is_file($oldBackup . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay' . DIRECTORY_SEPARATOR . 'preprocessing.php'),
        'Full-directory swap did not replace the stale active hook with the inert staged member.');
}

function overlay_test_loader_case(string $stageSource, string $ownerBase, string $manifestPath, string $capturedCore): void
{
    $repoRoot = dirname(__DIR__, 3);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-overlay-loader-' . bin2hex(random_bytes(8));
    $pluginRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $helperPath = $pluginRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    $stageRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
    try {
        $manifest = overlay_test_read_manifest($manifestPath);
        $stageMap = overlay_test_stage_map($manifest);
        $pins = overlay_test_current_pins($manifest);
        $upstream = $manifest['upstream'];
        overlay_test_mkdir(dirname($helperPath));
        overlay_test_mkdir($privateRoot);
        overlay_test_copy_files($stageSource, $ownerRoot, OVERLAY_TEST_FILES);
        overlay_test_owner_manifest($ownerRoot);
        overlay_test_copy_tree($stageSource, $stageRoot, $stageMap);
        overlay_test_expect(@copy($manifestPath, $stageRoot . DIRECTORY_SEPARATOR . 'source-manifest.json'),
            'Could not stage the pinned source manifest for the captured-loader fixture.');
        overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . 'preprocessing.php',
            $stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
            'Could not create the captured loader legacy duplicate.');
        $backupPath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
        overlay_test_write($backupPath, overlay_test_make_backup($ownerRoot, $ownerBase,
            $upstream['version'], $upstream['commit'], $pins));
        $productionHelper = $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
        overlay_test_expect(@copy($productionHelper, $helperPath), 'Could not copy production helper into loader fixture.');
        require_once $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth.php';
        require $helperPath;

        $before = overlay_test_capture_loader($fixtureRoot, $capturedCore,
            $fixtureRoot . DIRECTORY_SEPARATOR . 'before-loader.json');
        overlay_test_expect($before['exitCode'] === 0, 'Pre-migration captured loader child failed: ' . (string)$before['stderr']);
        overlay_test_expect(($before['trace']['hookCount'] ?? null) === 2
            && ($before['trace']['hookPaths'] ?? null) === [
                overlay_test_normalize_path($ownerRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                overlay_test_normalize_path($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
            ]
            && ($before['trace']['httpStatus'] ?? null) === 409
            && ($before['trace']['requestType'] ?? null) === 'instruction'
            && ($before['trace']['route'] ?? null) === 'solo_reflection'
            && ($before['trace']['originRequestType'] ?? null) === 'inputtext'
            && ($before['trace']['terminalReason'] ?? null) === 'background_paused',
            'Pinned recursive loader did not reproduce two-hook excluded-player Solo collision: ' . json_encode($before['trace']));

        $result = overlay_test_apply($fixtureRoot, $ownerRoot, $privateRoot);
        overlay_test_expect($result === ['state' => 'already_enabled', 'reason' => null],
            'Known stale duplicate could not be removed from an already-enabled owner.');
        overlay_test_expect(!file_exists($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php')
            && !is_link($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
            'Overlay migration left the active duplicate at the exact-basename hook path.');
        $after = overlay_test_capture_loader($fixtureRoot, $capturedCore,
            $fixtureRoot . DIRECTORY_SEPARATOR . 'after-loader.json');
        overlay_test_expect($after['exitCode'] === 0, 'Post-migration captured loader child failed: ' . (string)$after['stderr']);
        overlay_test_expect(($after['trace']['hookCount'] ?? null) === 1
            && ($after['trace']['hookPaths'] ?? null) === [
                overlay_test_normalize_path($ownerRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
            ]
            && ($after['trace']['httpStatus'] ?? null) === 200
            && ($after['trace']['requestType'] ?? null) === 'instruction'
            && ($after['trace']['route'] ?? null) === 'solo_reflection'
            && ($after['trace']['originRequestType'] ?? null) === 'inputtext'
            && ($after['trace']['originDialogue'] ?? null) === 'Dragonborn: I wait quietly.',
            'Post-migration exact-basename loader did not process excluded-player Solo once: ' . json_encode($after['trace']));
        overlay_test_full_dir_swap($fixtureRoot, $stageSource, $manifestPath, $stageMap);
        echo json_encode(['case' => 'loader-excluded-solo', 'before' => $before['trace'], 'after' => $after['trace'],
            'fullDirectorySwap' => 'passed', 'passed' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        overlay_test_remove_tree($fixtureRoot);
    }
}

function overlay_test_case(
    string $case,
    string $stageSource,
    string $ownerBase,
    string $manifestPath,
    string $legacyOwnerRoot
): void {
    $repoRoot = dirname(__DIR__, 3);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-overlay-' . bin2hex(random_bytes(8));
    $pluginRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $helperPath = $pluginRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    $archivePath = $privateRoot . DIRECTORY_SEPARATOR . OVERLAY_TEST_ARCHIVE;
    $backupPath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';

    try {
        overlay_test_mkdir(dirname($helperPath));
        $productionHelper = $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
        overlay_test_expect(@copy($productionHelper, $helperPath), 'Could not copy production helper into fixture.');
        overlay_test_expect(hash_file('sha256', $helperPath) === hash_file('sha256', $productionHelper),
            'Fixture helper differs from production.');
        overlay_test_mkdir($fixtureRoot . DIRECTORY_SEPARATOR . 'data');
        overlay_test_mkdir($privateRoot);
        overlay_test_copy_files($ownerBase, $ownerRoot, OVERLAY_TEST_FILES);
        overlay_test_owner_manifest($ownerRoot);
        $manifest = overlay_test_read_manifest($manifestPath);
        $stageMap = overlay_test_stage_map($manifest);
        $pins = overlay_test_current_pins($manifest);
        $upstream = $manifest['upstream'];
        if (in_array($case, ['already-postimage', 'already-postimage-stale-hook-cleanup', 'postimage-without-backup', 'tampered-archive',
            'postimage-legacy-backup', 'postimage-tampered-current-backup'], true)) {
            foreach (OVERLAY_TEST_FILES as $name) {
                overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . $name, $ownerRoot . DIRECTORY_SEPARATOR . $name),
                    'Could not create current postimage owner fixture.');
            }
        }
        if ($case !== 'missing-stage') {
            $stageRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
            overlay_test_copy_tree($stageSource, $stageRoot, $stageMap);
            overlay_test_expect(@copy($manifestPath, $stageRoot . DIRECTORY_SEPARATOR . 'source-manifest.json'),
                'Could not stage the pinned source manifest.');
            if (in_array($case, ['legacy-hook-template-cleanup', 'already-postimage-stale-hook-cleanup',
                'legacy-upgrade-rollback', 'legacy-upgrade-rollback-active-restore-failure'], true)) {
                overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . 'preprocessing.php',
                    $stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                    'Could not create the exact legacy active preprocessing fixture.');
            } elseif ($case === 'legacy-hook-template-unknown') {
                overlay_test_write($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php', "<?php // unknown stale bytes\n");
            }
        }
        if ($case === 'owner-drift') {
            overlay_test_expect(@file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'scope.php', "\nfixture drift\n", FILE_APPEND) !== false,
                'Could not create owner drift fixture.');
        }

        if ($case === 'legacy-hook-template-cleanup') {
            $legacyHook = $stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php';
            $legacyHookBytes = @file_get_contents($legacyHook);
            $expectedHookPost = null;
            foreach ($manifest['files'] as $entry) {
                if (($entry['path'] ?? null) === 'preprocessing.php') {
                    $expectedHookPost = $entry['postimageSha256'] ?? null;
                    break;
                }
            }
            overlay_test_expect(is_string($legacyHookBytes) && is_string($expectedHookPost)
                && hash_equals($expectedHookPost, hash('sha256', $legacyHookBytes)),
                'Legacy active template fixture must be the exact pinned PCV preprocessing source.');
        }
        if ($case === 'legacy-hook-template-unknown') {
            $legacyHook = $stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php';
            $legacyHookBytes = @file_get_contents($legacyHook);
            overlay_test_expect(is_string($legacyHookBytes), 'Unknown stale hook fixture is missing.');
        }

        $initialOwner = overlay_test_owner_hashes($ownerRoot);
        if ($case === 'legacy-upgrade-rollback' || $case === 'legacy-upgrade-rollback-active-restore-failure') {
            overlay_test_failure_child($fixtureRoot, $ownerBase, $stageSource, $legacyOwnerRoot, $manifestPath,
                $case === 'legacy-upgrade-rollback-active-restore-failure');
            echo json_encode(['case' => $case, 'passed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
            return;
        }

        require_once $repoRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth.php';
        require $helperPath;
        if ($case === 'already-postimage' || $case === 'already-postimage-stale-hook-cleanup') {
            overlay_test_write($backupPath, overlay_test_make_backup($ownerRoot, $ownerBase,
                $upstream['version'], $upstream['commit'], $pins));
        } elseif ($case === 'legacy-upgrade' || $case === 'legacy-tampered'
            || $case === 'legacy-root-mismatch' || $case === 'foreign-active') {
            $legacyRaw = overlay_test_legacy_backup($ownerRoot, $legacyOwnerRoot);
            if ($case === 'legacy-tampered') {
                $state = json_decode(substr($legacyRaw, strlen(QD_GUARDED_JSON_PREFIX)), true);
                $state['files']['scope.php']['bytesBase64'] = base64_encode('tampered legacy bytes');
                $legacyRaw = QD_GUARDED_JSON_PREFIX . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } elseif ($case === 'legacy-root-mismatch') {
                $legacyRaw = overlay_test_legacy_backup($ownerRoot, $legacyOwnerRoot,
                    (realpath($fixtureRoot) ?: $fixtureRoot) . DIRECTORY_SEPARATOR . 'wrong-root');
            } elseif ($case === 'foreign-active') {
                $state = json_decode(substr($legacyRaw, strlen(QD_GUARDED_JSON_PREFIX)), true);
                $state['ownerVersion'] = '0.1.22';
                $legacyRaw = QD_GUARDED_JSON_PREFIX . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            overlay_test_write($backupPath, $legacyRaw);
        } elseif ($case === 'tampered-archive') {
            overlay_test_write($backupPath, overlay_test_make_backup($ownerRoot, $ownerBase,
                $upstream['version'], $upstream['commit'], $pins));
            $legacyRaw = overlay_test_legacy_backup($ownerRoot, $legacyOwnerRoot);
            overlay_test_write($archivePath, substr($legacyRaw, 0, -1) . 'x');
        } elseif ($case === 'postimage-legacy-backup') {
            overlay_test_write($backupPath, overlay_test_legacy_backup($ownerRoot, $legacyOwnerRoot));
        } elseif ($case === 'postimage-tampered-current-backup') {
            $currentRaw = overlay_test_make_backup($ownerRoot, $ownerBase,
                $upstream['version'], $upstream['commit'], $pins);
            $state = json_decode(substr($currentRaw, strlen(QD_GUARDED_JSON_PREFIX)), true);
            $state['files']['scope.php']['bytesBase64'] = base64_encode('tampered current bytes');
            overlay_test_write($backupPath, QD_GUARDED_JSON_PREFIX
                . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($case === 'current-backup-partial-recovery') {
            overlay_test_expect(@copy($stageSource . DIRECTORY_SEPARATOR . 'scope.php', $ownerRoot . DIRECTORY_SEPARATOR . 'scope.php'),
                'Could not create partial BASE/POST owner fixture.');
            overlay_test_write($backupPath, overlay_test_make_backup($ownerRoot, $ownerBase,
                $upstream['version'], $upstream['commit'], $pins));
        }

        $backupBefore = is_file($backupPath) && !is_link($backupPath) ? @file_get_contents($backupPath) : null;
        $result = overlay_test_apply($fixtureRoot, $ownerRoot, $privateRoot);
        $success = ['state' => 'enabled', 'reason' => null];
        $already = ['state' => 'already_enabled', 'reason' => null];
        if ($case === 'clean-repeat' || $case === 'current-backup-partial-recovery') {
            overlay_test_expect($result === $success, 'Clean PCV 0.1.24 install did not enable the overlay.');
            $expectedPost = array_combine(OVERLAY_TEST_FILES, array_map(
                static fn(string $name): string => hash_file('sha256', $stageSource . DIRECTORY_SEPARATOR . $name),
                OVERLAY_TEST_FILES,
            ));
            overlay_test_expect(is_array($expectedPost) && overlay_test_owner_hashes($ownerRoot) === $expectedPost,
                'Clean install did not produce all seven exact postimages.');
            $activeBefore = @file_get_contents($backupPath);
            overlay_test_expect(is_string($activeBefore), 'Clean install did not create a guarded active backup.');
            overlay_test_expect(overlay_test_apply($fixtureRoot, $ownerRoot, $privateRoot) === $already,
                'Clean second apply was not idempotent.');
            overlay_test_expect(hash_equals($activeBefore, (string)@file_get_contents($backupPath)),
                'Idempotent retry changed the active backup.');
            overlay_test_expect(!file_exists($archivePath), 'Clean install unexpectedly created a legacy archive.');
            if ($case === 'current-backup-partial-recovery') {
                overlay_test_expect(hash_equals($activeBefore, (string)@file_get_contents($backupPath)),
                    'Partial recovery changed the valid current backup.');
            }
        } elseif ($case === 'already-postimage') {
            overlay_test_expect($result === $already, 'All-postimage 0.1.24 owner with valid active backup was not recognized.');
        } elseif ($case === 'postimage-without-backup') {
            overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'owner_incompatible'],
                'All-postimage 0.1.24 owner without an active backup was not refused.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Missing-backup refusal changed owner bytes.');
        } elseif ($case === 'postimage-legacy-backup') {
            overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'owner_incompatible'],
                'All-postimage 0.1.24 owner incorrectly accepted a legacy-only active backup.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Legacy-only backup refusal changed owner bytes.');
        } elseif ($case === 'postimage-tampered-current-backup') {
            overlay_test_expect(($result['state'] ?? null) === 'unavailable',
                'All-postimage 0.1.24 owner incorrectly accepted a corrupted current backup.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Corrupted current-backup refusal changed owner bytes.');
        } elseif ($case === 'legacy-hook-template-cleanup') {
            overlay_test_expect($result === $success, 'Known stale PCV hook template prevented overlay setup.');
            $expectedPost = array_combine(OVERLAY_TEST_FILES, array_map(
                static fn(string $name): string => hash_file('sha256', $stageSource . DIRECTORY_SEPARATOR . $name),
                OVERLAY_TEST_FILES,
            ));
            overlay_test_expect(is_array($expectedPost) && overlay_test_owner_hashes($ownerRoot) === $expectedPost,
                'Legacy-template migration changed the exact owner postimages.');
            overlay_test_expect(!file_exists($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php')
                && !is_link($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                'Successful setup left the stale recursive-hook basename active.');
            $activeState = qd_pcv_overlay_backup_state($backupPath,
                realpath($fixtureRoot) ?: $fixtureRoot, qd_pcv_overlay_pins());
            $expectedBackup = [];
            foreach (qd_pcv_overlay_pins() as $name => $entry) {
                if (!hash_equals($entry['base'], $entry['post'])) {
                    $expectedBackup[$name] = @file_get_contents($ownerBase . DIRECTORY_SEPARATOR . $name);
                }
            }
            overlay_test_expect($activeState === $expectedBackup,
                'Known stale-hook migration did not preserve exact pinned PCV base bytes in the active backup.');
        } elseif ($case === 'already-postimage-stale-hook-cleanup') {
            overlay_test_expect($result === $already, 'Valid all-postimage owner was not recognized during stale-hook cleanup.');
            overlay_test_expect(!file_exists($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php')
                && !is_link($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                'Already-enabled setup left the stale recursive-hook basename active.');
            overlay_test_expect(is_string($backupBefore) && hash_equals($backupBefore, (string)@file_get_contents($backupPath)),
                'Already-enabled stale-hook cleanup changed the active backup bytes.');
        } elseif ($case === 'legacy-hook-template-unknown') {
            overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'staged_source_missing'],
                'Unknown stale hook bytes were not refused as an invalid staged source.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Unknown stale hook refusal changed PCV owner bytes.');
            overlay_test_expect(hash_equals((string)$legacyHookBytes,
                (string)@file_get_contents($stageRoot . DIRECTORY_SEPARATOR . 'preprocessing.php')),
                'Unknown stale hook refusal changed or removed the active hook.');
            overlay_test_expect($backupBefore === null && !file_exists($backupPath) && !is_link($backupPath),
                'Unknown stale hook refusal created or changed a backup.');
        } elseif ($case === 'owner-drift') {
            overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'owner_incompatible'],
                'Owner drift was not refused.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Owner drift refusal changed a target file.');
        } elseif ($case === 'missing-stage') {
            overlay_test_expect($result === ['state' => 'unavailable', 'reason' => 'staged_source_missing'],
                'Missing stage was not reported.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Missing stage changed a target file.');
        } elseif ($case === 'legacy-upgrade') {
            overlay_test_expect(is_string($legacyRaw) && str_starts_with($legacyRaw, QD_GUARDED_JSON_PREFIX),
                'Legacy backup fixture was not guarded.');
            $archiveBytes = @file_get_contents($archivePath);
            overlay_test_expect(is_string($archiveBytes) && hash_equals($legacyRaw, $archiveBytes),
                'Migration did not preserve exact legacy backup bytes in the archive.');
            overlay_test_expect($result === $success, 'Valid known 0.1.23 backup did not migrate to 0.1.24.');
            $activeState = qd_pcv_overlay_backup_state($backupPath, realpath($fixtureRoot) ?: $fixtureRoot, qd_pcv_overlay_pins());
            overlay_test_expect(is_array($activeState) && array_keys($activeState) === array_keys($pins),
                'Migration did not create an exact current active backup.');
            foreach ($activeState as $relative => $bytes) {
                overlay_test_expect($bytes === file_get_contents($ownerBase . DIRECTORY_SEPARATOR . $relative),
                    'New active backup does not hold exact 0.1.24 base bytes for ' . $relative . '.');
            }
            $activeCurrent = @file_get_contents($backupPath);
            overlay_test_expect(overlay_test_apply($fixtureRoot, $ownerRoot, $privateRoot) === $already,
                'Migrated all-postimage owner was not idempotent.');
            overlay_test_expect(hash_equals($legacyRaw, (string)@file_get_contents($archivePath)),
                'Idempotent migration retry changed the archived legacy bytes.');
            overlay_test_expect(hash_equals((string)$activeCurrent, (string)@file_get_contents($backupPath)),
                'Idempotent migration retry changed the current backup.');
        } elseif ($case === 'legacy-tampered' || $case === 'legacy-root-mismatch' || $case === 'foreign-active'
            || $case === 'tampered-archive') {
            overlay_test_expect(($result['state'] ?? null) === 'unavailable',
                'Invalid active/archive backup was not refused.');
            overlay_test_expect(overlay_test_owner_hashes($ownerRoot) === $initialOwner,
                'Invalid active/archive backup refusal changed owner bytes.');
            overlay_test_expect(!file_exists($archivePath) || $case === 'tampered-archive',
                'Invalid backup refusal created an archive.');
        } else {
            throw new RuntimeException('Unknown overlay migration case: ' . $case);
        }
        overlay_test_expect(overlay_test_lock_is_free($privateRoot), 'Install lock remained held after apply.');
        echo json_encode(['case' => $case, 'passed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        overlay_test_remove_tree($fixtureRoot);
    }
}

function overlay_test_parse_args(array $argv): array
{
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $name = $argv[$index];
        if (!in_array($name, ['--case', '--stage', '--owner-base', '--manifest', '--legacy-owner-root', '--captured-core',
            '--loader-run', '--fixture-root', '--trace-output'], true)
            || !isset($argv[$index + 1])) {
            throw new RuntimeException('Invalid overlay test argument list.');
        }
        $options[substr($name, 2)] = $argv[++$index];
    }
    return $options;
}

function overlay_test_real_path(array $options, string $key, bool $directory): string
{
    $path = realpath($options[$key] ?? '');
    overlay_test_expect(is_string($path) && ($directory ? is_dir($path) : is_file($path)),
        'Required explicit fixture path is unavailable: --' . $key);
    return $path;
}

$repoRoot = dirname(__DIR__, 3);
try {
    $options = overlay_test_parse_args($argv);
    if (($options['loader-run'] ?? null) === '1') {
        $fixtureRoot = overlay_test_real_path($options, 'fixture-root', true);
        $capturedCore = overlay_test_real_path($options, 'captured-core', false);
        $tracePath = $options['trace-output'] ?? null;
        overlay_test_expect(is_string($tracePath) && $tracePath !== '' && !str_contains($tracePath, "\0"),
            'Captured loader trace path is invalid.');
        overlay_test_loader_child($fixtureRoot, $capturedCore, $tracePath);
        exit(0);
    }
    $stageSource = overlay_test_real_path($options, 'stage', true);
    $ownerBase = overlay_test_real_path($options, 'owner-base', true);
    $manifestPath = overlay_test_real_path($options, 'manifest', false);
    $legacyOwnerRoot = overlay_test_real_path($options, 'legacy-owner-root', true);
    $capturedCore = overlay_test_real_path($options, 'captured-core', false);
    $cases = [
        'clean-repeat',
        'legacy-hook-template-cleanup',
        'loader-excluded-solo',
        'already-postimage-stale-hook-cleanup',
        'legacy-hook-template-unknown',
        'already-postimage',
        'postimage-without-backup',
        'postimage-legacy-backup',
        'postimage-tampered-current-backup',
        'current-backup-partial-recovery',
        'owner-drift',
        'missing-stage',
        'legacy-upgrade',
        'legacy-tampered',
        'legacy-root-mismatch',
        'foreign-active',
        'tampered-archive',
        'legacy-upgrade-rollback',
        'legacy-upgrade-rollback-active-restore-failure',
    ];
    if (isset($options['case'])) {
        overlay_test_expect(in_array($options['case'], $cases, true), 'Unknown overlay migration case.');
        if ($options['case'] === 'loader-excluded-solo') {
            overlay_test_loader_case($stageSource, $ownerBase, $manifestPath, $capturedCore);
        } else {
            overlay_test_case($options['case'], $stageSource, $ownerBase, $manifestPath, $legacyOwnerRoot);
        }
        exit(0);
    }

    $failures = [];
    foreach ($cases as $case) {
        $command = [
            PHP_BINARY, '-n', __FILE__, '--case', $case,
            '--stage', $stageSource, '--owner-base', $ownerBase,
            '--manifest', $manifestPath, '--legacy-owner-root', $legacyOwnerRoot,
            '--captured-core', $capturedCore,
        ];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
        overlay_test_expect(is_resource($process), 'Could not start isolated fixture process.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        echo trim((string)$stdout) . (trim((string)$stderr) !== '' ? PHP_EOL . trim((string)$stderr) : '') . PHP_EOL;
        if ($exitCode !== 0) {
            $failures[] = $case;
        }
    }
    exit($failures === [] ? 0 : 1);
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['passed' => false, 'error' => $failure->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
