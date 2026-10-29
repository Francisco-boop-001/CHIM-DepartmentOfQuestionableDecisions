<?php
declare(strict_types=1);

const PCV25_BACKUP_PREFIX = "<?php http_response_code(404); exit; __halt_compiler();\n";
const PCV25_FILES = [
    'installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php', 'reflection.php', 'scope.php', 'state.php',
];
const PCV25_OLD23_PINS = [
    'preprocessing.php' => '5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c',
    'scope.php' => '763ff62b129086f7c3d91fbccbb80e8db0ec71b04d11fc150c4881420bbe6992',
    'state.php' => '142f5a026740dfb629af925005c7e931396aa3dcde0d534002fb42b545c5664d',
];
const PCV25_OLD23_VERSION = '0.1.23';
const PCV25_OLD23_COMMIT = '77265202d7f4a47b88e1eb88131b21f4b0752020';

function pcv25UpgradeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pcv25UpgradeOptions(array $argv): array
{
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $name = $argv[$index];
        if (!in_array($name, ['--owner25-root', '--owner24-root', '--owner23-root', '--case', '--child'], true)
            || !isset($argv[$index + 1])) {
            throw new RuntimeException('Invalid PCV25 overlay upgrade test arguments.');
        }
        $options[substr($name, 2)] = $argv[++$index];
    }
    return $options;
}

function pcv25UpgradeReadManifest(string $path): array
{
    $manifest = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    pcv25UpgradeCheck(is_array($manifest) && is_array($manifest['upstream'] ?? null)
        && is_array($manifest['files'] ?? null), 'Pinned owner source manifest is invalid.');
    return $manifest;
}

function pcv25UpgradeRealDirectory(array $options, string $name): string
{
    $path = realpath($options[$name] ?? '');
    pcv25UpgradeCheck(is_string($path) && is_dir($path) && !is_link($options[$name] ?? ''),
        'Required owner server directory is unavailable: --' . $name . '.');
    return $path;
}

function pcv25UpgradeMkdir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create the isolated PCV25 upgrade fixture.');
    }
}

function pcv25UpgradeRemoveTree(string $path): void
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
            pcv25UpgradeRemoveTree($item->getPathname());
        }
    }
    @rmdir($path);
}

function pcv25UpgradeCopyPinnedFiles(string $source, string $destination, array $names): void
{
    pcv25UpgradeMkdir($destination);
    foreach ($names as $name) {
        $from = $source . DIRECTORY_SEPARATOR . $name;
        $to = $destination . DIRECTORY_SEPARATOR . $name;
        pcv25UpgradeCheck(is_file($from) && !is_link($from) && @copy($from, $to),
            'Pinned owner fixture file is missing: ' . $name . '.');
    }
}

function pcv25UpgradeHashes(string $root): array
{
    $hashes = [];
    foreach (PCV25_FILES as $name) {
        $hash = @hash_file('sha256', $root . DIRECTORY_SEPARATOR . $name);
        pcv25UpgradeCheck(is_string($hash), 'Could not hash fixture owner file: ' . $name . '.');
        $hashes[$name] = $hash;
    }
    return $hashes;
}

function pcv25UpgradeBackup(
    array $manifest,
    string $ownerSource,
    string $chimRoot,
    string $ownerRoot,
    ?array $legacyPins = null,
    ?string $legacyVersion = null,
    ?string $legacyCommit = null
): string {
    $files = [];
    foreach ($manifest['files'] as $entry) {
        if ($legacyPins !== null) {
            $name = $entry['path'] ?? null;
            if (!is_string($name) || !array_key_exists($name, $legacyPins)) {
                continue;
            }
            $baseHash = $legacyPins[$name];
        } else {
            if (($entry['modified'] ?? null) !== true) {
                continue;
            }
            $name = $entry['path'];
            $baseHash = $entry['baseSha256'];
        }
        $bytes = @file_get_contents($ownerSource . DIRECTORY_SEPARATOR . $name);
        pcv25UpgradeCheck(is_string($bytes) && hash_equals($baseHash, hash('sha256', $bytes)),
            'Owner backup fixture does not match the pinned base bytes: ' . $name . '.');
        $files[$name] = ['sha256' => $baseHash, 'bytesBase64' => base64_encode($bytes)];
    }
    pcv25UpgradeCheck($files !== [], 'Pinned owner backup fixture has no modified files.');
    $upstream = $manifest['upstream'];
    $raw = PCV25_BACKUP_PREFIX . json_encode([
        'schemaVersion' => 1,
        'chimRoot' => $chimRoot,
        'pcvRoot' => $ownerRoot,
        'ownerVersion' => $legacyVersion ?? $upstream['version'],
        'ownerCommit' => $legacyCommit ?? $upstream['commit'],
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    pcv25UpgradeCheck(strlen($raw) < 1048576, 'Generated owner backup fixture exceeds the production bound.');
    return $raw;
}

function pcv25UpgradeFixture(array $options, string $case): array
{
    $repo = dirname(__DIR__, 3);
    $sourceManifest24 = pcv25UpgradeReadManifest($repo . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR
        . 'companion-only' . DIRECTORY_SEPARATOR . 'pcv-0.1.24-source-manifest.json');
    $sourceManifest23 = pcv25UpgradeReadManifest($repo . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR
        . 'companion-only' . DIRECTORY_SEPARATOR . 'pcv-0.1.23-source-manifest.json');
    $owner25Source = pcv25UpgradeRealDirectory($options, 'owner25-root');
    $owner24Source = pcv25UpgradeRealDirectory($options, 'owner24-root');
    $owner23Source = pcv25UpgradeRealDirectory($options, 'owner23-root');
    $stageSource = $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.25' . DIRECTORY_SEPARATOR . 'server';
    $stageManifest = $repo . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR
        . 'companion-only' . DIRECTORY_SEPARATOR . 'pcv-0.1.25-source-manifest.json';
    pcv25UpgradeCheck(is_dir($stageSource) && is_file($stageManifest), 'PCV25 candidate source stage is unavailable.');

    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcv25-upgrade-' . bin2hex(random_bytes(8));
    $pluginRoot = $fixture . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions';
    $ownerRoot = $fixture . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $privateRoot = $fixture . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $stageRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
    pcv25UpgradeMkdir($pluginRoot . DIRECTORY_SEPARATOR . 'lib');
    pcv25UpgradeMkdir($stageRoot);
    pcv25UpgradeMkdir($privateRoot);

    $candidateMap = [
        'installation_guard.php' => 'installation_guard.php', 'log.php' => 'log.php', 'manifest.json' => 'manifest.json',
        'preprocessing.php' => 'preprocessing.php.template', 'reflection.php' => 'reflection.php',
        'scope.php' => 'scope.php', 'state.php' => 'state.php',
    ];
    pcv25UpgradeCopyPinnedFiles($owner25Source, $ownerRoot, PCV25_FILES);
    foreach ($candidateMap as $name => $stagedName) {
        pcv25UpgradeCheck(@copy($stageSource . DIRECTORY_SEPARATOR . $name, $stageRoot . DIRECTORY_SEPARATOR . $stagedName),
            'Could not stage PCV25 candidate file: ' . $name . '.');
    }
    pcv25UpgradeCheck(@copy($stageManifest, $stageRoot . DIRECTORY_SEPARATOR . 'source-manifest.json'),
        'Could not stage the PCV25 owner manifest.');

    $helperSource = $repo . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    pcv25UpgradeCheck(@copy($helperSource, $pluginRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php'),
        'Could not copy the actual overlay loader into the isolated fixture.');

    $chimRoot = realpath($fixture);
    $ownerRoot = realpath($ownerRoot);
    pcv25UpgradeCheck(is_string($chimRoot) && is_string($ownerRoot), 'Fixture canonical roots are unavailable.');
    $backupChimRoot = $case === 'wrong-chim-root' ? $chimRoot . DIRECTORY_SEPARATOR . 'wrong-chim-root' : $chimRoot;
    $backupOwnerRoot = $case === 'wrong-pcv-root' ? $ownerRoot . DIRECTORY_SEPARATOR . 'wrong-pcv-root' : $ownerRoot;
    $active24 = pcv25UpgradeBackup($sourceManifest24, $owner24Source, $backupChimRoot, $backupOwnerRoot);
    $archive23 = pcv25UpgradeBackup($sourceManifest23, $owner23Source, $chimRoot, $ownerRoot,
        PCV25_OLD23_PINS, PCV25_OLD23_VERSION, PCV25_OLD23_COMMIT);
    $activePath = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
    $archive24Path = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup-v0.1.24.php';
    $archive23Path = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup-v0.1.23.php';
    if ($case !== 'clean') {
        if ($case === 'tampered-active') {
            $active24 = substr($active24, 0, -1) . 'x';
        }
        $activeInitial = $case === 'legacy23-upgrade' ? $archive23 : $active24;
        pcv25UpgradeCheck(file_put_contents($activePath, $activeInitial, LOCK_EX) === strlen($activeInitial),
            'Could not write the exact prior24 active backup fixture.');
    }
    if ($case === 'matching-archive' || $case === 'tampered-archive') {
        $archive24 = $case === 'tampered-archive' ? substr($active24, 0, -1) . 'x' : $active24;
        pcv25UpgradeCheck(file_put_contents($archive24Path, $archive24, LOCK_EX) === strlen($archive24),
            'Could not write the prior24 archive fixture.');
    }
    if ($case !== 'clean' && $case !== 'legacy23-upgrade') {
        pcv25UpgradeCheck(file_put_contents($archive23Path, $archive23, LOCK_EX) === strlen($archive23),
            'Could not write the exact prior23 archive fixture.');
    }
    if ($case === 'mixed-owner') {
        pcv25UpgradeCheck(@copy($stageSource . DIRECTORY_SEPARATOR . 'scope.php', $ownerRoot . DIRECTORY_SEPARATOR . 'scope.php'),
            'Could not create the mixed owner-version fixture.');
    }
    if ($case === 'tampered-stage') {
        $path = $stageRoot . DIRECTORY_SEPARATOR . 'scope.php';
        pcv25UpgradeCheck(file_put_contents($path, "\n// tampered candidate\n", FILE_APPEND | LOCK_EX) !== false,
            'Could not create the tampered stage fixture.');
    }
    return [
        'root' => $fixture, 'chimRoot' => $chimRoot, 'ownerRoot' => $ownerRoot, 'privateRoot' => $privateRoot,
        'stageRoot' => $stageRoot, 'activePath' => $activePath, 'archive24Path' => $archive24Path,
        'archive23Path' => $archive23Path, 'active24' => $active24, 'active23' => $archive23, 'archive23' => $archive23,
        'owner25Hashes' => pcv25UpgradeHashes($ownerRoot), 'owner25Source' => $owner25Source,
    ];
}

function pcv25UpgradeChild(array $fixture, bool $injectFailure): array
{
    $command = [PHP_BINARY, __FILE__, '--child', $fixture['root'], $injectFailure ? 'rollback' : 'normal'];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, null, ['bypass_shell' => true]);
    pcv25UpgradeCheck(is_resource($process), 'Could not start the isolated native PHP overlay fixture.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    pcv25UpgradeCheck($exit === 0 && is_string($stdout) && $stderr === '',
        'Isolated overlay apply failed: exit=' . $exit . ' stdout=' . (string)$stdout . ' stderr=' . (string)$stderr);
    $result = json_decode(trim($stdout), true, 16, JSON_THROW_ON_ERROR);
    pcv25UpgradeCheck(is_array($result) && is_array($result['result'] ?? null), 'Overlay child returned invalid JSON.');
    return $result;
}

function pcv25UpgradeRawBackup(string $path): ?string
{
    if (!is_file($path) || is_link($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    return is_string($raw) ? $raw : null;
}

function pcv25UpgradeCurrentBackup(string $path, array $fixture, array $candidateManifest): void
{
    $raw = pcv25UpgradeRawBackup($path);
    pcv25UpgradeCheck(is_string($raw) && str_starts_with($raw, PCV25_BACKUP_PREFIX),
        'PCV25 migration did not create a guarded active backup.');
    $state = json_decode(substr($raw, strlen(PCV25_BACKUP_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    $modified = [];
    foreach ($candidateManifest['files'] as $entry) {
        if (($entry['modified'] ?? null) === true) {
            $modified[$entry['path']] = $entry['baseSha256'];
        }
    }
    pcv25UpgradeCheck(($state['ownerVersion'] ?? null) === '0.1.25'
        && ($state['ownerCommit'] ?? null) === '886a54967db7c6c08725c8bec8854ea3919ea532'
        && ($state['chimRoot'] ?? null) === $fixture['chimRoot']
        && ($state['pcvRoot'] ?? null) === $fixture['ownerRoot']
        && is_array($state['files'] ?? null) && array_keys($state['files']) === array_keys($modified),
        'PCV25 migration created an active backup with the wrong identity or closure.');
    foreach ($modified as $name => $expectedHash) {
        $bytes = base64_decode($state['files'][$name]['bytesBase64'] ?? '', true);
        $original = file_get_contents($fixture['owner25Source'] . DIRECTORY_SEPARATOR . $name);
        pcv25UpgradeCheck(is_string($bytes) && is_string($original) && $bytes === $original
            && hash_equals($expectedHash, hash('sha256', $bytes))
            && ($state['files'][$name]['sha256'] ?? null) === $expectedHash,
            'PCV25 active backup does not preserve the exact clean owner base bytes for ' . $name . '.');
    }
}

if (($argv[1] ?? null) === '--child') {
    $fixtureRoot = realpath($argv[2] ?? '');
    $mode = $argv[3] ?? null;
    pcv25UpgradeCheck(is_string($fixtureRoot) && is_dir($fixtureRoot) && in_array($mode, ['normal', 'rollback'], true),
        'Invalid isolated overlay child arguments.');
    $repo = dirname(__DIR__, 3);
    if ($mode === 'rollback') {
        define('QD_GUARDED_JSON_PREFIX', PCV25_BACKUP_PREFIX);
        $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
        $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
        $GLOBALS['pcv25_fail_owner_scope'] = true;
        $GLOBALS['pcv25_injected_owner_failure'] = false;
        $GLOBALS['pcv25_previous_archive_path'] = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup-v0.1.24.php';
        $GLOBALS['pcv25_active_backup_path'] = $privateRoot . DIRECTORY_SEPARATOR . 'pcv-overlay-backup.php';
        $GLOBALS['pcv25_archive_ready_at_injection'] = false;
        function qd_path_key(string $path): string
        {
            return strtolower(str_replace('/', '\\', $path));
        }
        function qd_managed_file_path(string $path, string $privateRoot, bool $mustExist): ?string
        {
            if (is_link($privateRoot) || is_link($path)) {
                return null;
            }
            $root = realpath($privateRoot);
            $parent = realpath(dirname($path));
            if (!is_string($root) || !is_string($parent) || !is_dir($parent)) {
                return null;
            }
            $candidate = $parent . DIRECTORY_SEPARATOR . basename($path);
            if (!str_starts_with(qd_path_key($candidate), rtrim(qd_path_key($root), '\\') . '\\')
                || ($mustExist && !is_file($candidate))) {
                return null;
            }
            return $candidate;
        }
        function qd_atomic_write(string $path, string $content, bool $guarded = false): void
        {
            if (!$guarded && basename($path) === 'scope.php' && $GLOBALS['pcv25_fail_owner_scope']) {
                $GLOBALS['pcv25_fail_owner_scope'] = false;
                $GLOBALS['pcv25_injected_owner_failure'] = true;
                $previous = @file_get_contents($GLOBALS['pcv25_previous_archive_path']);
                $active = @file_get_contents($GLOBALS['pcv25_active_backup_path']);
                $GLOBALS['pcv25_archive_ready_at_injection'] = is_string($previous) && is_string($active)
                    && str_starts_with($active, QD_GUARDED_JSON_PREFIX);
                throw new RuntimeException('Injected owner write failure after prior backup migration.');
            }
            $bytes = $guarded ? QD_GUARDED_JSON_PREFIX . $content : $content;
            if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Fixture atomic write failed.');
            }
        }
    } else {
        require_once $repo . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth.php';
    }
    require $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions'
        . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    $result = qd_pcv_overlay_apply([
        'chimRoot' => $fixtureRoot,
        'privateRoot' => $privateRoot,
        'webRoot' => $fixtureRoot . DIRECTORY_SEPARATOR . 'webroot',
        'pcvRoot' => $ownerRoot,
    ]);
    echo json_encode([
        'result' => $result,
        'ownerHashes' => pcv25UpgradeHashes($ownerRoot),
        'injected' => $GLOBALS['pcv25_injected_owner_failure'] ?? false,
        'archiveReadyAtInjection' => $GLOBALS['pcv25_archive_ready_at_injection'] ?? false,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$repo = dirname(__DIR__, 3);
try {
    $options = pcv25UpgradeOptions($argv);
    $cases = isset($options['case']) ? [$options['case']] : [
        'clean', 'upgrade', 'legacy23-upgrade', 'matching-archive', 'tampered-active', 'tampered-archive',
        'wrong-chim-root', 'wrong-pcv-root', 'mixed-owner', 'tampered-stage', 'rollback',
    ];
    $knownCases = ['clean', 'upgrade', 'legacy23-upgrade', 'matching-archive', 'tampered-active', 'tampered-archive',
        'wrong-chim-root', 'wrong-pcv-root', 'mixed-owner', 'tampered-stage', 'rollback'];
    foreach ($cases as $case) {
        pcv25UpgradeCheck(in_array($case, $knownCases, true), 'Unknown PCV25 overlay upgrade case: ' . $case . '.');
        $fixture = pcv25UpgradeFixture($options, $case);
        try {
            $candidateManifest = pcv25UpgradeReadManifest($repo . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR
                . 'companion-only' . DIRECTORY_SEPARATOR . 'pcv-0.1.25-source-manifest.json');
            $activeBefore = pcv25UpgradeRawBackup($fixture['activePath']);
            $archive24Before = pcv25UpgradeRawBackup($fixture['archive24Path']);
            $archive23Before = pcv25UpgradeRawBackup($fixture['archive23Path']);
            $result = pcv25UpgradeChild($fixture, $case === 'rollback');
            $ownerUnchanged = $result['ownerHashes'] === $fixture['owner25Hashes'];

            if ($case === 'clean') {
                pcv25UpgradeCheck(($result['result'] ?? null) === ['state' => 'enabled', 'reason' => null],
                    'Clean PCV25 owner did not enable the candidate overlay.');
                pcv25UpgradeCurrentBackup($fixture['activePath'], $fixture, $candidateManifest);
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['archive24Path']) === null
                    && pcv25UpgradeRawBackup($fixture['archive23Path']) === null,
                    'Clean PCV25 install created an unrelated prior-version archive.');
            } elseif ($case === 'upgrade' || $case === 'matching-archive') {
                pcv25UpgradeCheck(($result['result'] ?? null) === ['state' => 'enabled', 'reason' => null],
                    'A clean PCV25 owner with a valid PCV24 active backup did not migrate.');
                pcv25UpgradeCheck($case === 'upgrade' ? $archive24Before === null : $archive24Before === $fixture['active24'],
                    'PCV24 archive fixture did not represent the intended first-upgrade or matching-archive state.');
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['archive24Path']) === $fixture['active24'],
                    'PCV24 active backup bytes were not preserved exactly in the v0.1.24 archive.');
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['archive23Path']) === $fixture['archive23'],
                    'Existing PCV23 archive bytes changed during the PCV25 migration.');
                pcv25UpgradeCurrentBackup($fixture['activePath'], $fixture, $candidateManifest);
                $activeCurrent = pcv25UpgradeRawBackup($fixture['activePath']);
                $again = pcv25UpgradeChild($fixture, false);
                pcv25UpgradeCheck(($again['result'] ?? null) === ['state' => 'already_enabled', 'reason' => null]
                    && pcv25UpgradeRawBackup($fixture['activePath']) === $activeCurrent
                    && pcv25UpgradeRawBackup($fixture['archive24Path']) === $fixture['active24']
                    && pcv25UpgradeRawBackup($fixture['archive23Path']) === $fixture['archive23'],
                    'PCV25 migration retry changed an active backup or historical archive.');
            } elseif ($case === 'legacy23-upgrade') {
                pcv25UpgradeCheck(($result['result'] ?? null) === ['state' => 'enabled', 'reason' => null]
                    && $archive24Before === null && $archive23Before === null,
                    'A clean PCV25 owner with a valid active PCV23 backup did not start from the expected legacy state.');
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['archive23Path']) === $fixture['active23']
                    && pcv25UpgradeRawBackup($fixture['archive24Path']) === null,
                    'PCV23 active backup bytes were not archived exactly or an unrelated PCV24 archive was created.');
                pcv25UpgradeCurrentBackup($fixture['activePath'], $fixture, $candidateManifest);
                $activeCurrent = pcv25UpgradeRawBackup($fixture['activePath']);
                $again = pcv25UpgradeChild($fixture, false);
                pcv25UpgradeCheck(($again['result'] ?? null) === ['state' => 'already_enabled', 'reason' => null]
                    && pcv25UpgradeRawBackup($fixture['activePath']) === $activeCurrent
                    && pcv25UpgradeRawBackup($fixture['archive23Path']) === $fixture['active23'],
                    'Direct PCV23 migration retry changed an active backup or archive.');
            } elseif ($case === 'rollback') {
                pcv25UpgradeCheck(($result['result']['state'] ?? null) === 'unavailable'
                    && ($result['result']['reason'] ?? null) === 'write_failed' && ($result['injected'] ?? null) === true
                    && ($result['archiveReadyAtInjection'] ?? null) === true
                    && $activeBefore === $fixture['active24'] && $archive24Before === null
                    && $archive23Before === $fixture['archive23'],
                    'Injected owner write failure did not reach the expected rollback path.');
                pcv25UpgradeCheck($ownerUnchanged, 'Rollback did not restore every exact clean PCV25 owner byte.');
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['activePath']) === $fixture['active24']
                    && pcv25UpgradeRawBackup($fixture['archive24Path']) === null
                    && pcv25UpgradeRawBackup($fixture['archive23Path']) === $fixture['archive23'],
                    'Rollback did not restore exact PCV24 active and PCV23 archive bytes.');
            } else {
                pcv25UpgradeCheck(($result['result']['state'] ?? null) === 'unavailable' && $ownerUnchanged,
                    'Invalid or mixed prior-version state was not refused without changing owner bytes.');
                pcv25UpgradeCheck(pcv25UpgradeRawBackup($fixture['activePath']) === $activeBefore
                    && pcv25UpgradeRawBackup($fixture['archive24Path']) === $archive24Before
                    && pcv25UpgradeRawBackup($fixture['archive23Path']) === $archive23Before,
                    'Invalid or mixed prior-version state changed an active backup or archive.');
            }
            echo 'PASS PCV25 overlay upgrade case: ' . $case . PHP_EOL;
        } finally {
            pcv25UpgradeRemoveTree($fixture['root']);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
