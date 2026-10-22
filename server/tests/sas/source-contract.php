<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'sas.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n source-contract.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

function sasContractExpect(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}

function sasContractCleanup(string $root): bool
{
    if (!file_exists($root) && !is_link($root)) return true;
    $base = realpath(sys_get_temp_dir());
    $real = realpath($root);
    if (!is_string($base) || !is_string($real) || is_link($root)
        || strcasecmp(dirname($real), $base) !== 0
        || !str_starts_with(basename($real), 'qd-sas-source-')) return false;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isLink() ? !@unlink($entry->getPathname())
            : ($entry->isDir() ? !@rmdir($entry->getPathname()) : !@unlink($entry->getPathname()))) return false;
    }
    return @rmdir($real);
}

$report = [
    'ready' => false,
    'errorReason' => null,
    'owner' => ['version' => null, 'selectedClosurePins' => []],
    'department' => ['adapter' => ['file' => 'adapters/sas.php', 'sha256' => null]],
    'checks' => [
        'adapterLoaded' => false,
        'manifestReady' => false,
        'sourceIdentityReady' => false,
        'readerLoaded' => false,
        'perPinDriftRefused' => false,
        'unsupportedVersionRefused' => false,
    ],
    'limits' => [
        'sourceOnly' => true,
        'database' => false,
        'http' => false,
        'skyrim' => false,
        'ownerRuntimeLoaded' => false,
        'ownerPureReadersLoaded' => false,
    ],
];
$tempRoot = null;

try {
    $ownerReal = realpath($ownerRoot);
    $adapterReal = realpath($adapterPath);
    sasContractExpect(is_string($ownerReal) && is_dir($ownerReal), 'owner_source_unavailable');
    sasContractExpect(is_string($adapterReal) && is_file($adapterReal), 'department_adapter_unavailable');
    putenv('QD_SAS_ROOT=' . $ownerReal);
    $adapterHash = @hash_file('sha256', $adapterReal);
    sasContractExpect(is_string($adapterHash), 'department_adapter_unavailable');
    $report['department']['adapter']['sha256'] = strtolower($adapterHash);
    require $adapterReal;
    $report['checks']['adapterLoaded'] = true;

    $expectedHashes = [
        'manifest.json' => 'cca252133723d6667d65b93034d6c3b6173fb6a71a3f18b41e4ec322dcd96743',
        'ledger.php' => 'cb59355c40fc74a8bd7119d810de2c82457a5b8e6b6453923e226900e4a80375',
        'substance.php' => '30c4086c46a280009c11cfc22f3964f1ed9d72d8a6779938d6790d5057021852',
        'vocab.php' => 'a24b6d116d304adbf504eafc1be29899dbc15b89e4a3d4b9ed0fdf458bec5d5b',
        'written_evidence.php' => '63d26c22684068195d640be79af830e10978d3a0a1b684e90865f1ca3bac3f51',
        'rows.php' => 'e91816c80c4e7b70c303ce2131669de01de75e23a93a5e2da181242ebfe24c38',
    ];
    sasContractExpect(QD_SAS_EXPECTED_VERSION === '0.1.12'
        && QD_SAS_EXPECTED_SOURCE_HASHES === $expectedHashes, 'adapter_contract_drift');
    $manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
    $report['owner']['version'] = is_array($manifest) ? ($manifest['version'] ?? null) : null;
    $sourceFiles = qd_sas_source_files($ownerReal);
    sasContractExpect(array_keys($sourceFiles) === array_keys($expectedHashes), 'owner_closure_drift');
    foreach ($expectedHashes as $relative => $expectedHash) {
        $source = $sourceFiles[$relative];
        $actualHash = @hash_file('sha256', $source);
        $matches = is_string($actualHash) && hash_equals($expectedHash, strtolower($actualHash));
        $report['owner']['selectedClosurePins'][] = [
            'file' => $relative,
            'expectedSha256' => $expectedHash,
            'actualSha256' => is_string($actualHash) ? strtolower($actualHash) : null,
            'matches' => $matches,
        ];
        sasContractExpect($matches, 'owner_source_drift');
    }
    $report['checks']['manifestReady'] = qd_sas_manifest_ready($ownerReal);
    sasContractExpect($report['checks']['manifestReady'], 'owner_source_drift');
    $report['checks']['sourceIdentityReady'] = qd_sas_source_root() === $ownerReal;
    sasContractExpect($report['checks']['sourceIdentityReady'], 'owner_identity_unavailable');

    sasContractExpect(qd_sas_load_owner_sources() === $ownerReal, 'owner_source_load_failed');
    $reader = 'ChimSwornAndScorned\\normalizeLedger';
    $readerPath = function_exists($reader) ? realpath((new ReflectionFunction($reader))->getFileName() ?: '') : false;
    $emptyLedger = function_exists($reader)
        ? \ChimSwornAndScorned\normalizeLedger(null, '41')
        : null;
    $report['checks']['readerLoaded'] = is_string($readerPath)
        && $readerPath === realpath($ownerReal . DIRECTORY_SEPARATOR . 'ledger.php')
        && $emptyLedger === [
            'playthrough_id' => '41',
            'floor_event_id' => 0,
            'processed' => [],
            'issues' => [],
            'seeded' => [],
        ];
    $pureReaderPaths = array_map(
        static fn(string $file): string|false => realpath($ownerReal . DIRECTORY_SEPARATOR . $file),
        ['ledger.php', 'vocab.php', 'substance.php', 'written_evidence.php', 'rows.php']
    );
    $loadedPureReaderPaths = [];
    foreach (get_included_files() as $includedPath) {
        $includedReal = realpath($includedPath);
        if (!is_string($includedReal) || !str_starts_with($includedReal, $ownerReal . DIRECTORY_SEPARATOR)) continue;
        if (in_array($includedReal, $pureReaderPaths, true)) {
            $loadedPureReaderPaths[$includedReal] = true;
        } else {
            $report['limits']['ownerRuntimeLoaded'] = true;
        }
    }
    $report['limits']['ownerPureReadersLoaded'] = count($loadedPureReaderPaths) === count($pureReaderPaths);
    sasContractExpect($report['checks']['readerLoaded'] && $report['limits']['ownerPureReadersLoaded'], 'owner_reader_source_mismatch');
    sasContractExpect(!$report['limits']['ownerRuntimeLoaded'], 'owner_runtime_loaded');

    $tempRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qd-sas-source-' . bin2hex(random_bytes(8));
    $stagedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'owner';
    sasContractExpect(@mkdir($stagedRoot, 0700, true), 'temporary_staging_failed');
    foreach ($sourceFiles as $relative => $source) {
        if (!@copy($source, $stagedRoot . DIRECTORY_SEPARATOR . $relative)) throw new RuntimeException('temporary_staging_failed');
    }
    sasContractExpect(qd_sas_manifest_ready($stagedRoot), 'staged_source_rejected');
    foreach ($expectedHashes as $relative => $_expectedHash) {
        $path = $stagedRoot . DIRECTORY_SEPARATOR . $relative;
        $bytes = @file_get_contents($path);
        sasContractExpect(is_string($bytes), 'temporary_staging_failed');
        sasContractExpect(@file_put_contents($path, $bytes . ' ', LOCK_EX) !== false, 'temporary_staging_failed');
        $refused = !qd_sas_manifest_ready($stagedRoot);
        sasContractExpect(@file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 'temporary_staging_failed');
        sasContractExpect($refused && qd_sas_manifest_ready($stagedRoot), 'owner_pin_drift_accepted');
    }
    $report['checks']['perPinDriftRefused'] = true;

    $manifestPath = $stagedRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifestBytes = @file_get_contents($manifestPath);
    sasContractExpect(is_string($manifestBytes), 'temporary_staging_failed');
    $unsupportedManifest = json_decode($manifestBytes, true);
    sasContractExpect(is_array($unsupportedManifest), 'temporary_staging_failed');
    $unsupportedManifest['version'] = '0.1.11';
    $unsupportedBytes = json_encode($unsupportedManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    sasContractExpect(@file_put_contents($manifestPath, $unsupportedBytes, LOCK_EX) !== false, 'temporary_staging_failed');
    $report['checks']['unsupportedVersionRefused'] = !qd_sas_manifest_ready($stagedRoot);
    sasContractExpect($report['checks']['unsupportedVersionRefused'], 'unsupported_owner_version_accepted');
    $report['ready'] = true;
} catch (Throwable $error) {
    $report['errorReason'] = $error->getMessage() !== '' ? $error->getMessage() : 'source_contract_check_failed';
} finally {
    if (is_string($tempRoot) && !sasContractCleanup($tempRoot)) {
        $report['ready'] = false;
        $report['errorReason'] = 'temporary_cleanup_failed';
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($report['ready'] ? 0 : 1);
