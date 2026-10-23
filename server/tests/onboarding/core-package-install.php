<?php
declare(strict_types=1);

const QD_PACKAGE_TEST_MANAGER_SHA256 = 'b178780e1d67e740f52582001fed98c4edbf7c4a3d29712e84fcf72bc15b7f82';
const QD_PACKAGE_TEST_CORE_FILES = [
    'playthrough_preferences.php' => ['fc9562fad12b867a5593d06bc0b0b8167120b0270088c343ae3db074e4b3f475', '02ec005e7109af689056783bf54d9b3080fcfa16194c5dfda0526c5add4eaf41'],
    'playthrough_home.php' => ['e28ced10ee4410f0926f9366fca7bb80199b2f423c80afb1c960d074564b88b6', 'ac55d75bea4ab30828390cba4bfc50ecb3db91e43c72080705c00abbac1ef18b'],
    'playthrough_runtime.php' => ['d480af9d658c8e754b78d1e4ca6056544d21942facbafd94b23ab1da4cb1cd9c', 'ec29467c61873f255d3bc13962466ab04f12b3bc2bd6d29276e53878088a1d7e'],
];

function qd_package_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function qd_package_test_clear_environment(): void
{
    foreach (['QD_CHIM_ROOT', 'QD_PAIRING_FILE', 'QD_SERVER_STATE_FILE', 'QD_WEB_ROOT', 'QD_PCV_ROOT', 'QD_MP_ROOT', 'QD_SAS_ROOT'] as $name) {
        putenv($name);
        qd_package_test_expect(getenv($name) === false, 'Could not clear a Department path override for the default-install test.');
    }
}

function qd_package_test_uuid(): string
{
    return sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
}

function qd_package_test_directory(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create an isolated test directory.');
    }
}

function qd_package_test_copy_pinned_file(string $source, string $destination, array $expectedHashes): void
{
    $actual = @hash_file('sha256', $source);
    qd_package_test_expect(is_string($actual) && in_array(strtolower($actual), $expectedHashes, true), 'Pinned CHIM source hash mismatch: ' . basename($source));
    qd_package_test_expect(@copy($source, $destination), 'Could not copy pinned CHIM source into the fake layout: ' . basename($source));
}

function qd_package_test_outer_manifest(string $packagePath): array
{
    qd_package_test_expect(class_exists(ZipArchive::class), 'PHP ZipArchive is required for the real core package manager.');
    $archive = new ZipArchive();
    qd_package_test_expect($archive->open($packagePath) === true, 'Could not open DWPkg: ' . basename($packagePath));
    try {
        $raw = $archive->getFromName('manifest.json');
        $manifest = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        qd_package_test_expect(is_array($manifest), 'DWPkg outer manifest must be a JSON object.');
        qd_package_test_expect(($manifest['schema_version'] ?? null) === 4, 'DWPkg must use the pinned core schema 4.');
        return $manifest;
    } finally {
        $archive->close();
    }
}

function qd_package_test_installed(string $serverRoot, string $name, string $version): string
{
    $path = $serverRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . $name;
    qd_package_test_expect(is_dir($path) && !is_link($path), 'Core installer did not activate ' . $name . '.');
    $manifestPath = $path . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = json_decode((string)@file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    qd_package_test_expect(is_array($manifest)
        && ($manifest['name'] ?? null) === $name
        && ($manifest['version'] ?? null) === $version,
        'Installed extension manifest does not match the DWPkg identity: ' . $name);
    return $path;
}

function qd_package_test_api_dependencies(bool $rejectMutation, ?int &$dispatchCount = null): array
{
    return [
        'withProfileLease' => static fn(callable $callback): mixed => $callback(),
        'currentScope' => static fn(string $pairId): string => 'synthetic-package-install-profile',
        'readiness' => static fn(): array => ['pcv' => true, 'mp' => false, 'sas' => false, 'correspondence' => false],
        'perspectives' => static fn(string $scopeKey): array => [
            'mp' => ['choices' => [], 'limited' => true],
            'sas' => ['choices' => [], 'limited' => true],
        ],
        'operations' => [
            'pcv.arm' => static function (stdClass $payload, array $context) use ($rejectMutation, &$dispatchCount): array {
                if ($rejectMutation) {
                    throw new RuntimeException('A completed journal operation was dispatched again.');
                }
                ($context['beginCommit'])();
                if ($dispatchCount !== null) {
                    $dispatchCount++;
                }
                return ['operationId' => $payload->operationId, 'state' => 'staged'];
            },
        ],
    ];
}

function qd_package_test_request(string $operation, ?string $serverContext, string $viewToken, string $generation, array $payload): string
{
    return json_encode((object)[
        'version' => 1,
        'requestId' => qd_package_test_uuid(),
        'operation' => $operation,
        'context' => (object)[
            'viewToken' => $viewToken,
            'nativeGeneration' => $generation,
            'serverContext' => $serverContext,
        ],
        'payload' => (object)$payload,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function qd_package_test_response(array $response, string $phase): array
{
    qd_package_test_expect(($response['status'] ?? null) === 200, $phase . ' returned HTTP ' . (string)($response['status'] ?? 'unknown'));
    $body = json_decode((string)($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    qd_package_test_expect(is_array($body) && ($body['ok'] ?? null) === true && is_array($body['data'] ?? null), $phase . ' did not return API success.');
    return $body['data'];
}

function qd_package_test_private_snapshot(string $privateRoot): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($privateRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $path = $entry->getPathname();
        $relative = substr($path, strlen(rtrim($privateRoot, '\\/')) + 1);
        $bytes = @file_get_contents($path);
        qd_package_test_expect(is_string($bytes) && str_starts_with($bytes, QD_GUARDED_JSON_PREFIX), 'Private fixture file lacks the PHP web-access guard: ' . $relative);
        $files[str_replace('\\', '/', $relative)] = hash('sha256', $bytes);
    }
    ksort($files, SORT_STRING);
    qd_package_test_expect(isset($files['pairing.php'], $files['state.php'], $files['connect-claims.php']), 'The connection, bootstrap, and state paths were not all persisted.');
    qd_package_test_expect(count(array_filter(array_keys($files), static fn(string $path): bool => str_starts_with($path, 'journal/'))) >= 3, 'The guarded journal was not persisted.');
    return $files;
}

function qd_package_test_reopen(string $fixtureRoot, string $bearer, string $viewToken, string $generation, array $payload): array
{
    $input = json_encode([
        'bearer' => $bearer,
        'viewToken' => $viewToken,
        'generation' => $generation,
        'payload' => $payload,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $command = [PHP_BINARY, '-n', __FILE__, '--reopen', $fixtureRoot];
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    qd_package_test_expect(is_resource($process), 'Could not start a clean PHP process for post-upgrade replay verification.');
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    qd_package_test_expect($exitCode === 0, 'Clean-process replay check failed: ' . trim((string)$stdout . ' ' . (string)$stderr));
    $result = json_decode((string)$stdout, true, 16, JSON_THROW_ON_ERROR);
    qd_package_test_expect(is_array($result) && ($result['ok'] ?? null) === true && ($result['replayed'] ?? null) === true
        && ($result['dispatched'] ?? null) === false, 'Updated package did not reopen and replay the completed journal record.');
    return $result;
}

function qd_package_test_reopen_mode(string $fixtureRoot): never
{
    try {
        $input = json_decode((string)stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        qd_package_test_expect(is_array($input) && is_string($input['bearer'] ?? null)
            && is_string($input['viewToken'] ?? null) && is_string($input['generation'] ?? null)
            && is_array($input['payload'] ?? null), 'Reopen request input is malformed.');
        $serverRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'HerikaServer';
        qd_package_test_clear_environment();
        $_SERVER['DOCUMENT_ROOT'] = $serverRoot . DIRECTORY_SEPARATOR . 'www';
        require_once $serverRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions' . DIRECTORY_SEPARATOR . 'connect.php';
        $settings = qd_api_settings_from_environment();
        qd_package_test_expect($settings['coreRoot'] === realpath($serverRoot)
            && $settings['chimRoot'] === realpath($serverRoot), 'Clean-process API root was not derived from the installed extension layout.');
        $dependencies = qd_package_test_api_dependencies(true);
        $viewToken = $input['viewToken'];
        $generation = $input['generation'];
        $bootstrapBody = qd_package_test_request('bootstrap', null, $viewToken, $generation, []);
        $bootstrap = qd_package_test_response(qd_api_handle('POST', 'application/json', $bootstrapBody, $input['bearer'], $settings, $dependencies), 'post-upgrade bootstrap');
        qd_package_test_expect(($bootstrap['authenticated'] ?? null) === true && is_string($bootstrap['serverContext'] ?? null), 'Post-upgrade package did not authenticate bootstrap.');
        $operationBody = qd_package_test_request('pcv.arm', $bootstrap['serverContext'], $viewToken, $generation, $input['payload']);
        $operation = qd_package_test_response(qd_api_handle('POST', 'application/json', $operationBody, $input['bearer'], $settings, $dependencies), 'post-upgrade replay');
        qd_package_test_expect(($operation['state'] ?? null) === 'staged', 'Post-upgrade replay returned unexpected operation data.');
        fwrite(STDOUT, json_encode(['ok' => true, 'replayed' => true, 'dispatched' => false], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

function qd_package_test_main(array $arguments): int
{
    if (count($arguments) !== 5 || PHP_SAPI !== 'cli') {
        fwrite(STDERR, "Usage: php core-package-install.php <pinned-HerikaServer-root> <questionable_decisions.dwpkg> <pcv_companion.dwpkg> <fresh-/tmp-fixture-root>\n");
        return 2;
    }

    $pinnedRoot = realpath($arguments[1]);
    $departmentPackage = realpath($arguments[2]);
    $companionPackage = realpath($arguments[3]);
    $fixtureRoot = $arguments[4];
    qd_package_test_expect(is_string($pinnedRoot) && is_dir($pinnedRoot), 'Pinned core source directory is unavailable.');
    qd_package_test_expect(is_string($departmentPackage) && is_file($departmentPackage), 'Department DWPkg is unavailable.');
    qd_package_test_expect(is_string($companionPackage) && is_file($companionPackage), 'Companion DWPkg is unavailable.');
    qd_package_test_expect(!file_exists($fixtureRoot) && !is_link($fixtureRoot), 'Fixture root must be a fresh, unused path.');
    $tempRoot = realpath(sys_get_temp_dir());
    $fixtureParent = realpath(dirname($fixtureRoot));
    qd_package_test_expect(is_string($tempRoot) && is_string($fixtureParent)
        && ($fixtureParent === $tempRoot || str_starts_with($fixtureParent, rtrim($tempRoot, '\\/') . DIRECTORY_SEPARATOR)),
        'Fixture root must be under the local temporary directory.');

    $managerSource = $pinnedRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'plugin_package_manager.php';
    $managerHash = @hash_file('sha256', $managerSource);
    qd_package_test_expect(is_string($managerHash) && hash_equals(QD_PACKAGE_TEST_MANAGER_SHA256, strtolower($managerHash)), 'Package manager source does not match the pinned installer bytes.');
    $departmentManifest = qd_package_test_outer_manifest($departmentPackage);
    $companionManifest = qd_package_test_outer_manifest($companionPackage);
    foreach ([[$departmentManifest, 'questionable_decisions'], [$companionManifest, 'pcv_companion']] as [$manifest, $expectedName]) {
        qd_package_test_expect(($manifest['name'] ?? null) === $expectedName
            && ($manifest['version'] ?? null) === '0.1.0-prealpha.8', 'DWPkg outer identity does not match the prealpha.8 package set: ' . $expectedName);
    }

    qd_package_test_directory($fixtureRoot);
    $serverRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'HerikaServer';
    foreach (['lib', 'data', 'ext', 'www'] as $directory) {
        qd_package_test_directory($serverRoot . DIRECTORY_SEPARATOR . $directory);
    }
    qd_package_test_expect(@copy($managerSource, $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'plugin_package_manager.php'), 'Could not install the pinned core manager into the fake layout.');
    foreach (QD_PACKAGE_TEST_CORE_FILES as $relative => $hashes) {
        qd_package_test_copy_pinned_file(
            $pinnedRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $relative,
            $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $relative,
            $hashes,
        );
    }
    require_once $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'plugin_package_manager.php';
    $stateRoot = $serverRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'plugin_packages';
    $migrationLog = [];
    $manager = new DwemerPluginPackageManager(
        $serverRoot,
        $stateRoot,
        static function (string $target, string $name, array $migrations) use (&$migrationLog): void {
            $migrationLog[] = [$name, array_map('basename', $migrations)];
        },
    );
    foreach ([[$departmentPackage, $departmentManifest], [$companionPackage, $companionManifest]] as [$package, $manifest]) {
        $probe = $manager->probe($manifest['name'], $manifest['version']);
        qd_package_test_expect(($probe['upload_required'] ?? null) === true, 'Fresh fake core should request initial package activation.');
        $installed = $manager->installArchive($package, basename($package), $manifest['name'], $manifest['version']);
        qd_package_test_expect(($installed['status'] ?? null) === 'completed', 'Pinned core installer failed to activate ' . $manifest['name'] . '.');
        qd_package_test_installed($serverRoot, $manifest['name'], $manifest['version']);
        qd_package_test_expect(($manager->probe($manifest['name'], $manifest['version'])['upload_required'] ?? true) === false, 'Core package status did not record the installed version.');
    }

    $departmentRoot = qd_package_test_installed($serverRoot, 'questionable_decisions', '0.1.0-prealpha.8');
    $companionRoot = qd_package_test_installed($serverRoot, 'pcv_companion', '0.1.0-prealpha.8');
    $companionFiles = [];
    $companionIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($companionRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($companionIterator as $entry) {
        if ($entry->isFile() && !$entry->isLink() && basename($entry->getPathname()) === 'companion.php') {
            $companionFiles[] = $entry->getPathname();
        }
    }
    qd_package_test_expect(count($companionFiles) === 1, 'Installed companion DWPkg must contain exactly one companion.php integration file.');

    qd_package_test_clear_environment();
    $_SERVER['DOCUMENT_ROOT'] = $serverRoot . DIRECTORY_SEPARATOR . 'www';
    require_once $departmentRoot . DIRECTORY_SEPARATOR . 'connect.php';
    require_once $companionFiles[0];
    qd_package_test_expect(function_exists('pcv_companion_with_current_pair_context'), 'Installed companion integration did not load.');
    $expectedApiPath = realpath($departmentRoot . DIRECTORY_SEPARATOR . 'api.php');
    $expectedDispatcherPath = realpath($departmentRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php');
    foreach (['qd_api_settings_from_environment', 'qd_api_runtime_dependencies'] as $function) {
        qd_package_test_expect(realpath((new ReflectionFunction($function))->getFileName() ?: '') === $expectedApiPath, 'Installed API wrapper resolved from an unexpected source file.');
    }
    qd_package_test_expect(realpath((new ReflectionFunction('qd_with_locked_companion_context'))->getFileName() ?: '') === $expectedDispatcherPath, 'Installed companion context wrapper resolved from an unexpected source file.');

    $settings = qd_api_settings_from_environment();
    $privateRoot = realpath($serverRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private');
    qd_package_test_expect($settings['coreRoot'] === realpath($serverRoot)
        && $settings['chimRoot'] === realpath($serverRoot)
        && is_string($privateRoot) && $settings['privateRoot'] === $privateRoot
        && $settings['pairingPath'] === $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php'
        && $settings['statePath'] === $privateRoot . DIRECTORY_SEPARATOR . 'state.php', 'Default private state did not resolve into the stable CHIM data namespace.');
    qd_package_test_expect(!str_starts_with(strtolower($privateRoot), strtolower($departmentRoot . DIRECTORY_SEPARATOR)), 'Private state must remain outside the extension tree replaced by the package manager.');

    $claimId = qd_package_test_uuid();
    $bearer = qd_new_token();
    $claimBody = json_encode(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => hash('sha256', $bearer)], JSON_THROW_ON_ERROR);
    $begin = qd_connect_handle('POST', 'application/json', $claimBody, $settings, time());
    $beginBody = json_decode((string)$begin['body'], true, 16, JSON_THROW_ON_ERROR);
    qd_package_test_expect(($begin['status'] ?? null) === 200 && ($beginBody['state'] ?? null) === 'pending', 'Installed connection route did not create a pending claim.');
    qd_package_test_expect(qd_connect_approve($settings, $claimId, time()) === 'approved', 'Explicit approval did not activate the pending game claim.');
    $auth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], 'Bearer ' . $bearer, $settings['privateRoot'], $settings['coreRoot']);
    qd_package_test_expect(is_array($auth) && $auth['scopes'] === ['compose', 'inspector', 'pcv'], 'Approved game bearer did not authenticate with default scopes.');

    $dispatchCount = 0;
    $dependencies = qd_package_test_api_dependencies(false, $dispatchCount);
    $viewToken = qd_package_test_uuid();
    $generation = '1';
    $bootstrapData = qd_package_test_response(
        qd_api_handle('POST', 'application/json', qd_package_test_request('bootstrap', null, $viewToken, $generation, []), 'Bearer ' . $bearer, $settings, $dependencies),
        'installed API bootstrap',
    );
    qd_package_test_expect(($bootstrapData['authenticated'] ?? null) === true
        && is_string($bootstrapData['serverContext'] ?? null)
        && ($bootstrapData['capabilities']['pcv'] ?? null) === true,
        'Installed API bootstrap did not authenticate the approved game.');

    $payload = [
        'operationId' => qd_package_test_uuid(),
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => null,
        'configuration' => [
            'enabled' => true,
            'scene_mode' => 'solo',
            'actor_a' => 'npc:22',
            'exclude_player' => true,
            'bystander_mode' => 'exclude',
        ],
    ];
    $operationBody = qd_package_test_request('pcv.arm', $bootstrapData['serverContext'], $viewToken, $generation, $payload);
    $firstOperation = qd_package_test_response(qd_api_handle('POST', 'application/json', $operationBody, 'Bearer ' . $bearer, $settings, $dependencies), 'installed API journal write');
    $replayedOperation = qd_package_test_response(qd_api_handle('POST', 'application/json', $operationBody, 'Bearer ' . $bearer, $settings, $dependencies), 'installed API journal replay');
    qd_package_test_expect(($firstOperation['state'] ?? null) === 'staged'
        && ($replayedOperation['state'] ?? null) === 'staged'
        && $dispatchCount === 1,
        'The actual API did not mark, complete, and replay the guarded mutation journal.');

    $snapshot = qd_package_test_private_snapshot($privateRoot);
    $lockPath = $privateRoot . DIRECTORY_SEPARATOR . 'journal' . DIRECTORY_SEPARATOR . 'journal.lock.php';
    $lock = @fopen($lockPath, 'c+b');
    qd_package_test_expect(is_resource($lock) && @flock($lock, LOCK_EX), 'Could not hold the managed journal lock across a package update.');
    $lockBefore = fstat($lock);
    $apiHashBefore = hash_file('sha256', $departmentRoot . DIRECTORY_SEPARATOR . 'api.php');
    $upgrade = $manager->installArchive($departmentPackage, basename($departmentPackage), 'questionable_decisions', '0.1.0-prealpha.8');
    qd_package_test_expect(($upgrade['status'] ?? null) === 'completed', 'Pinned core manager failed to replace the Department package.');
    clearstatcache(true, $lockPath);
    $lockAfter = @stat($lockPath);
    $lockStable = is_array($lockBefore) && is_array($lockAfter)
        && ($lockBefore['dev'] ?? null) === ($lockAfter['dev'] ?? null)
        && ($lockBefore['ino'] ?? null) === ($lockAfter['ino'] ?? null);
    @flock($lock, LOCK_UN);
    fclose($lock);
    qd_package_test_expect($lockStable, 'Package replacement changed the inode held by the managed journal lock.');
    $departmentRootAfter = qd_package_test_installed($serverRoot, 'questionable_decisions', '0.1.0-prealpha.8');
    qd_package_test_expect(hash_file('sha256', $departmentRootAfter . DIRECTORY_SEPARATOR . 'api.php') === $apiHashBefore, 'Repeated package activation did not restore the exact package API bytes.');
    qd_package_test_expect(realpath($serverRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private') === $privateRoot
        && qd_package_test_private_snapshot($privateRoot) === $snapshot,
        'Pairing, claim, server state, or completed replay journal changed across extension replacement.');

    $reopen = qd_package_test_reopen($fixtureRoot, 'Bearer ' . $bearer, $viewToken, '2', $payload);
    qd_package_test_expect(qd_package_test_installed($serverRoot, 'pcv_companion', '0.1.0-prealpha.8') === $companionRoot, 'Companion package activation path changed during Department replacement.');
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'fixtureRoot' => $fixtureRoot,
        'coreManagerSha256' => strtolower((string)$managerHash),
        'department' => ['name' => 'questionable_decisions', 'version' => '0.1.0-prealpha.8', 'reinstalled' => true],
        'companion' => ['name' => 'pcv_companion', 'version' => '0.1.0-prealpha.8', 'installed' => true],
        'privateFiles' => count($snapshot),
        'journalLockStable' => $lockStable,
        'freshProcessReplay' => $reopen['replayed'],
        'scopeAndLeaseDependencies' => 'synthetic; no database/provider access',
        'migrationCallback' => 'synthetic; no database access',
        'fixtureRetained' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    return 0;
}

if (($argv[1] ?? null) === '--reopen') {
    qd_package_test_reopen_mode($argv[2] ?? '');
}

try {
    exit(qd_package_test_main($argv));
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL core package install: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
