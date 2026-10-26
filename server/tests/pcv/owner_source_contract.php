<?php
declare(strict_types=1);

$implementationRoot = dirname(__DIR__, 3);
$serverRoot = $implementationRoot . DIRECTORY_SEPARATOR . 'server';
$ownerRoot = $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
    . 'private-conversation-v0.1.24' . DIRECTORY_SEPARATOR . 'server';
$adapterPath = $serverRoot . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';
$manifestPath = $implementationRoot . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR . 'companion-only'
    . DIRECTORY_SEPARATOR . 'pcv-0.1.24-source-manifest.json';
$checks = [
    'ownerPositiveLoad' => false,
    'tamperedSourceRefused' => false,
    'actualReaderShape' => false,
    'wrapUpSceneDirection' => false,
    'includedPlayerFallback' => false,
    'lockedOwnerCallbacks' => false,
    'privateTextboxTokenRoute' => false,
    'ownerRevisionChangesPreserved' => false,
];
$selectedClosurePins = [];
$adapterHash = is_file($adapterPath) ? strtolower((string)hash_file('sha256', $adapterPath)) : null;
$errorReason = null;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-owner-contract-' . bin2hex(random_bytes(8));
$badRoot = $tempRoot . DIRECTORY_SEPARATOR . 'tampered';
$stateRoot = $tempRoot . DIRECTORY_SEPARATOR . 'state';
$logRoot = $tempRoot . DIRECTORY_SEPARATOR . 'logs';
$previousRoot = getenv('QD_PCV_ROOT');
$previousLogRoot = getenv('PCV_LOG_DIR');

require_once $serverRoot . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'runtime_support.php';
require_once $adapterPath;
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';

try {
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest['upstream']['version'] ?? null) !== QD_PCV_EXPECTED_VERSION
        || ($manifest['upstream']['tag'] ?? null) !== 'private_conversation-v0.1.24'
        || ($manifest['upstream']['commit'] ?? null) !== PCV_COMPANION_OWNER_UPSTREAM_COMMIT
        || QD_PCV_EXPECTED_VERSION !== '0.1.24'
        || PCV_COMPANION_OWNER_VERSION !== QD_PCV_EXPECTED_VERSION
        || ($manifest['stagedSourceDirectory'] ?? null) !== 'pcv-companion/private-conversation-v0.1.24/server'
        || ($manifest['installDirectory'] ?? null) !== 'ext/private_conversation'
        || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('owner_manifest_mismatch');
    }

    $manifestFiles = [];
    foreach ($manifest['files'] as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null)) {
            throw new RuntimeException('owner_manifest_shape_invalid');
        }
        $manifestFiles[$file['path']] = $file;
    }
    if (count($manifestFiles) !== count(QD_PCV_EXPECTED_OWNER_SOURCE_HASHES)
        || count($manifestFiles) !== count(PCV_COMPANION_OWNER_BASE_SOURCE_HASHES)
        || count($manifestFiles) !== count(PCV_COMPANION_OWNER_SOURCE_HASHES)) {
        throw new RuntimeException('owner_manifest_closure_mismatch');
    }
    foreach (QD_PCV_EXPECTED_OWNER_SOURCE_HASHES as $relative => $expectedHash) {
        $path = $ownerRoot . DIRECTORY_SEPARATOR . $relative;
        $actualHash = is_file($path) && !is_link($path) ? strtolower((string)hash_file('sha256', $path)) : '';
        $entry = $manifestFiles[$relative] ?? null;
        $baseHash = is_array($entry) ? strtolower((string)($entry['baseSha256'] ?? '')) : '';
        $postimageHash = is_array($entry) ? strtolower((string)($entry['postimageSha256'] ?? '')) : '';
        $matches = is_array($entry)
            && hash_equals($expectedHash, $actualHash)
            && hash_equals($expectedHash, $postimageHash)
            && hash_equals(PCV_COMPANION_OWNER_SOURCE_HASHES[$relative] ?? '', $expectedHash)
            && hash_equals(PCV_COMPANION_OWNER_BASE_SOURCE_HASHES[$relative] ?? '', $baseHash)
            && ($entry['modified'] ?? null) === !hash_equals($baseHash, $postimageHash);
        $selectedClosurePins[] = [
            'file' => $relative,
            'expectedSha256' => $expectedHash,
            'actualSha256' => $actualHash,
            'matches' => $matches,
        ];
        if (!$matches) {
            throw new RuntimeException('owner_source_pin_mismatch');
        }
    }

    if (!mkdir($tempRoot, 0700) || !mkdir($badRoot, 0700) || !mkdir($stateRoot, 0700) || !mkdir($logRoot, 0700)) {
        throw new RuntimeException('temporary_fixture_unavailable');
    }
    foreach (QD_PCV_EXPECTED_OWNER_SOURCE_HASHES as $relative => $_expectedHash) {
        if (!copy($ownerRoot . DIRECTORY_SEPARATOR . $relative, $badRoot . DIRECTORY_SEPARATOR . $relative)) {
            throw new RuntimeException('temporary_owner_copy_failed');
        }
    }
    if (file_put_contents($badRoot . DIRECTORY_SEPARATOR . 'scope.php', "\n", FILE_APPEND | LOCK_EX) !== 1) {
        throw new RuntimeException('temporary_source_tamper_failed');
    }
    putenv('QD_PCV_ROOT=' . $badRoot);
    try {
        qd_pcv_load_owner_sources();
    } catch (RuntimeException) {
        $checks['tamperedSourceRefused'] = true;
    }
    putenv('QD_PCV_ROOT=' . $ownerRoot);
    $loadedRoot = qd_pcv_load_owner_sources();
    $checks['ownerPositiveLoad'] = realpath($loadedRoot) === realpath($ownerRoot);

    $expectedFunctions = [
        'pcv_load_store' => 'state.php',
        'pcv_visible_state' => 'state.php',
        'pcv_current_identity' => 'state.php',
        'pcv_read_npc_catalog' => 'state.php',
        'pcvScopeKnownNpcs' => 'scope.php',
        'pcvScopeLoadNpcCatalog' => 'scope.php',
        'pcvPrepareScopedInput' => 'scope.php',
    ];
    $readerShape = true;
    foreach ($expectedFunctions as $function => $file) {
        $source = function_exists($function) ? realpath((new ReflectionFunction($function))->getFileName() ?: '') : false;
        $readerShape = $readerShape && $source === realpath($ownerRoot . DIRECTORY_SEPARATOR . $file);
    }
    $homeState = [
        'available' => true,
        'active_id' => 42,
        'auto_switch_pending' => false,
        'playthroughs' => [[
            'id' => 42,
            'active' => true,
            'available' => true,
            'character_id' => '0123456789abcdef0123456789abcdef',
            'player_name' => 'Department Test Player',
        ]],
    ];
    $key = qd_pcv_owner_key_from_home_state($homeState);
    if (!is_string($key)) {
        throw new RuntimeException('canonical_owner_key_unavailable');
    }
    file_put_contents($stateRoot . DIRECTORY_SEPARATOR . 'state.lock', '');
    putenv('PCV_LOG_DIR=' . $logRoot);
    pcv_write_store($stateRoot, pcv_empty_store($key));
    $read = qd_pcv_read_state_directory($stateRoot, $key);
    $checks['actualReaderShape'] = $readerShape && ($read['kind'] ?? null) === 'ready'
        && ($read['state']['key'] ?? null) === $key;

    $scope = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => 'Lydia',
        'actor_b' => 'Aela',
        'exclude_player' => false,
        'bystander_mode' => 'exclude',
    ];
    $input = ['inputtext', '1700000000', '1700000000', 'Dragonborn: keep the fallback', 'e30='];
    $fallback = pcvPrepareScopedInput($input, [], $scope, 'Dragonborn', 'STANDARD');
    $direction = pcvPrepareScopedInput($input, [], $scope, 'Dragonborn', 'STANDARD', true);
    $checks['includedPlayerFallback'] = ($fallback['status'] ?? null) === 'applied'
        && ($fallback['game_request'][0] ?? null) === 'inputtext'
        && ($fallback['game_request'][3] ?? null) === $input[3];
    $checks['wrapUpSceneDirection'] = ($direction['status'] ?? null) === 'applied'
        && ($direction['game_request'][0] ?? null) === 'instruction'
        && ($direction['game_request'][3] ?? null) === 'Dragonborn: keep the fallback';

    $stateSource = (string)file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'state.php');
    $preprocessingSource = (string)file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'preprocessing.php');
    $checks['lockedOwnerCallbacks'] = function_exists('pcv_companion_run_locked_commit')
        && str_contains($preprocessingSource, 'PCV_COMPANION_OWNER_PRECOMMIT');
    $checks['privateTextboxTokenRoute'] = str_contains($preprocessingSource, 'wireTokenHash')
        && str_contains($preprocessingSource, 'PCV_COMPANION_CLAIM')
        && str_contains($preprocessingSource, 'pcv_companion_after_owner_preprocessing');
    $checks['ownerRevisionChangesPreserved'] = str_contains($stateSource, 'Only a live scene can consume a rejected replacement')
        && str_contains($stateSource, '$state[\'pending\'] = null;')
        && str_contains($preprocessingSource, '$mode, $wrapUp);');

    if ($adapterHash === null || in_array(false, $checks, true)) {
        throw new RuntimeException('source_contract_check_failed');
    }
} catch (Throwable $error) {
    $errorReason = $error->getMessage();
} finally {
    is_string($previousRoot) ? putenv('QD_PCV_ROOT=' . $previousRoot) : putenv('QD_PCV_ROOT');
    is_string($previousLogRoot) ? putenv('PCV_LOG_DIR=' . $previousLogRoot) : putenv('PCV_LOG_DIR');
    if (file_exists($tempRoot) || is_link($tempRoot)) {
        $systemTempRoot = realpath(sys_get_temp_dir());
        $resolvedTempRoot = realpath($tempRoot);
        $parentMatches = is_string($systemTempRoot) && is_string($resolvedTempRoot)
            && (DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp(dirname($resolvedTempRoot), $systemTempRoot) === 0
                : dirname($resolvedTempRoot) === $systemTempRoot);
        $ownedTempRoot = $parentMatches && !is_link($tempRoot)
            && str_starts_with(basename($resolvedTempRoot), 'qd-pcv-owner-contract-');
        $cleanupFailed = false;
        if ($ownedTempRoot && is_dir($resolvedTempRoot)) {
            try {
                $items = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($resolvedTempRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($items as $item) {
                    $itemPath = $item->getPathname();
                    $removed = $item->isLink() ? @unlink($itemPath)
                        : ($item->isDir() ? @rmdir($itemPath) : @unlink($itemPath));
                    if (!$removed && (file_exists($itemPath) || is_link($itemPath))) {
                        $cleanupFailed = true;
                    }
                }
                if (!@rmdir($resolvedTempRoot) && is_dir($resolvedTempRoot)) {
                    $cleanupFailed = true;
                }
            } catch (Throwable) {
                $cleanupFailed = true;
            }
        } else {
            $cleanupFailed = true;
        }
        if ($cleanupFailed) {
            $errorReason = $errorReason === null ? 'temporary_fixture_cleanup_failed'
                : $errorReason . ';temporary_fixture_cleanup_failed';
        }
    }
}
$receipt = [
    'ready' => $errorReason === null,
    'errorReason' => $errorReason,
    'owner' => [
        'version' => QD_PCV_EXPECTED_VERSION,
        'tag' => 'private_conversation-v0.1.24',
        'commit' => PCV_COMPANION_OWNER_UPSTREAM_COMMIT,
        'selectedClosurePins' => $selectedClosurePins,
    ],
    'checks' => $checks,
    'limits' => [
        'sourceOnly' => true,
        'temporaryState' => true,
        'database' => false,
        'http' => false,
        'skyrim' => false,
        'runtime' => false,
    ],
    'department' => [
        'adapter' => [
            'file' => 'adapters/pcv.php',
            'sha256' => $adapterHash,
        ],
    ],
];
echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (!$receipt['ready']) {
    exit(1);
}
