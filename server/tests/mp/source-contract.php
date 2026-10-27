<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . '/adapters/mp.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) $ownerRoot = $argv[++$i];
    elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) $adapterPath = $argv[++$i];
    else { fwrite(STDERR, "usage: php -n source-contract.php --owner-root MP_SERVER_DIR [--adapter PATH]\n"); exit(2); }
}
function mpSourceExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function mpSourceHash(string $path): ?string { $hash = @hash_file('sha256', $path); return is_string($hash) ? $hash : null; }
function mpSourceFixture(string $path, string $ownerRoot, array $pins): void
{
    if (!mkdir($path, 0700, true)) throw new RuntimeException('Could not create a private source fixture.');
    foreach ($pins as $file => $_hash) {
        $source = $ownerRoot . DIRECTORY_SEPARATOR . $file;
        if (!is_file($source) || is_link($source) || !copy($source, $path . DIRECTORY_SEPARATOR . $file)) {
            throw new RuntimeException('Could not stage a pinned source fixture.');
        }
    }
}
function mpSourceCleanup(string $path): void
{
    $temp = realpath(sys_get_temp_dir());
    $root = realpath($path);
    mpSourceExpect(is_string($temp) && is_string($root) && str_starts_with($root, $temp . DIRECTORY_SEPARATOR), 'Refusing to clean a path outside the temporary directory.');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir()) { if (!rmdir($entry->getPathname())) throw new RuntimeException('Could not remove a temporary fixture directory.'); }
        elseif (!unlink($entry->getPathname())) throw new RuntimeException('Could not remove a temporary fixture file.');
    }
    if (!rmdir($root)) throw new RuntimeException('Could not remove the temporary fixture root.');
}
if ($ownerRoot === '' || extension_loaded('pgsql') || function_exists('pg_query')) {
    fwrite(STDERR, "Run with php -n and an exact owner source root.\n"); exit(2);
}
$expectedVersion = '0.1.30';
$expectedPins = [
    'manifest.json' => 'd2ac602e075768399b1495795c7de9735615e82e2d5047da6b99e69aac6c50da',
    'dashboard_data.php' => 'e693cd74200783792c385b5288253631f168a59f759e28fd2a600227a2a747e9',
    'store.php' => '3d91e0756559cb892d218cace864b93e57200f2a4e53bfbb7ff9504afd93ba78',
    'influence.php' => 'ff13458c3051b3654558466c0e63954679836cbd824f239cdb28893c352bf741',
    'intimacy.php' => 'eebec2379641b7e4eb7a626b9e4cdd139c15db95421b57ca7bdcb3b1dc65bd60',
    'logging.php' => '2f8e7f75cf9de12fa1757bc381100d48512156995a95842749ce3e69e4180d43',
    'restore_policy.php' => 'ae8fa5f5e694b45667a7eb4da7fd6da101244c231bbefe094dcdb8dadb10058a',
];
$checks = ['exactSourceReady' => false, 'storeReadAccepted' => false, 'unprofiledScopeRead' => false, 'oldVersionRefused' => false, 'unsupportedVersionRefused' => false, 'everyPinDriftRefused' => false];
$ownerPureReadersLoaded = false;
$ownerVersion = null;
$pins = [];
$errorReason = null;
$tempRoot = null;
$tempOwned = false;
$adapterReal = realpath($adapterPath);
$protocolReal = realpath(dirname(__DIR__, 2) . '/lib/protocol.php');
try {
    $ownerReal = realpath($ownerRoot);
    mpSourceExpect(is_string($ownerReal) && is_dir($ownerReal) && !is_link($ownerRoot), 'The exact MP source root is unavailable.');
    mpSourceExpect(is_string($adapterReal) && is_file($adapterReal) && is_string($protocolReal) && is_file($protocolReal), 'The Department source files are unavailable.');
    require_once $adapterReal;
    $manifest = json_decode((string)file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true, 8, JSON_THROW_ON_ERROR);
    $ownerVersion = is_array($manifest) ? ($manifest['version'] ?? null) : null;
    foreach ($expectedPins as $file => $expectedHash) {
        $actualHash = mpSourceHash($ownerReal . DIRECTORY_SEPARATOR . $file);
        $pins[] = ['file' => $file, 'expectedSha256' => $expectedHash, 'actualSha256' => $actualHash, 'matches' => $actualHash === $expectedHash];
    }
    $checks['exactSourceReady'] = $ownerVersion === $expectedVersion
        && ($manifest['name'] ?? null) === 'mind_poisoning'
        && QD_MP_EXPECTED_VERSION === $expectedVersion
        && QD_MP_EXPECTED_SOURCE_HASHES === $expectedPins
        && !in_array(false, array_column($pins, 'matches'), true)
        && qd_mp_manifest_ready($ownerReal);
    if ($checks['exactSourceReady']) {
        putenv('QD_MP_ROOT=' . $ownerReal);
        qd_mp_load_owner_sources();
        foreach ([
            'ChimMindPoisoning\\dashboardReadLogs' => 'dashboard_data.php',
            'ChimMindPoisoning\\dashboardBuildInteractions' => 'dashboard_data.php',
            'ChimMindPoisoning\\dashboardOpinionOwnerId' => 'dashboard_data.php',
            'ChimMindPoisoning\\storedLedgerNamespace' => 'store.php',
            'ChimMindPoisoning\\mindPoisoningReadIntimacyFromRow' => 'intimacy.php',
        ] as $function => $file) {
            mpSourceExpect(function_exists($function) && realpath((new ReflectionFunction($function))->getFileName() ?: '') === realpath($ownerReal . DIRECTORY_SEPARATOR . $file), 'A pure MP reader loaded from the wrong source file.');
        }
        $ownerPureReadersLoaded = true;
        $npc = ['id' => 7, 'npc_name' => 'Mara'];
        $event = ['event_id' => 13, 'utterance_id' => 'utt_12345678'];
        $store = qd_mp_readonly_inspector_store([7 => $npc], ['13:utt_12345678' => $event]);
        $writeDenied = $updateDenied = false;
        try { $store->writeNpc(7, [], new stdClass(), 0.0); } catch (LogicException) { $writeDenied = true; }
        try { $store->npcById(7, true); } catch (LogicException) { $updateDenied = true; }
        $limited = false;
        $interactions = \ChimMindPoisoning\dashboardBuildInteractions(['active_playthrough' => '41', 'player_name' => 'Tester', 'identities' => [], 'rows' => []], [], $limited);
        $checks['storeReadAccepted'] = $store instanceof \ChimMindPoisoning\StoreDb
            && $store->npcById(7) === $npc && $store->eventById(13, 'utt_12345678') === $event
            && $writeDenied && $updateDenied && $interactions === [] && $limited === false;
        $normalOwner = ['id' => 7, 'npc_name' => 'Mara', 'plugin_extended_data' => new stdClass()];
        $normalStore = qd_mp_readonly_inspector_store([7 => $normalOwner], []);
        $normalCache = [];
        $normalArrangement = qd_mp_read_current_arrangements(
            $normalStore,
            ['profileId' => 'unprofiled', 'playerName' => 'Dragonborn', 'characterId' => null],
            $normalOwner,
            [['id' => 7, 'npc_name' => 'Mara']],
            ['player'],
            $normalCache,
        );
        $checks['unprofiledScopeRead'] = ($normalArrangement['values']['player'] ?? null) === 'none'
            && ($normalArrangement['limited'] ?? null) === false;
        $tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-mp-0130-' . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700)) throw new RuntimeException('Could not create private source fixtures.');
        $tempOwned = true;
        foreach ([['old', '0.1.29'], ['unsupported', '0.1.31']] as [$name, $version]) {
            $fixture = $tempRoot . DIRECTORY_SEPARATOR . $name;
            mpSourceFixture($fixture, $ownerReal, $expectedPins);
            $path = $fixture . DIRECTORY_SEPARATOR . 'manifest.json';
            $copyManifest = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $copyManifest['version'] = $version;
            if (file_put_contents($path, json_encode($copyManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Could not write a version fixture.');
            $key = $name === 'old' ? 'oldVersionRefused' : 'unsupportedVersionRefused';
            $checks[$key] = !qd_mp_manifest_ready($fixture);
        }
        $driftRoot = $tempRoot . DIRECTORY_SEPARATOR . 'drift';
        mpSourceFixture($driftRoot, $ownerReal, $expectedPins);
        $checks['everyPinDriftRefused'] = true;
        foreach ($expectedPins as $file => $_hash) {
            $path = $driftRoot . DIRECTORY_SEPARATOR . $file;
            $original = file_get_contents($path);
            if (!is_string($original) || file_put_contents($path, $original . ' ', LOCK_EX) === false) throw new RuntimeException('Could not write a drift fixture.');
            $refused = !qd_mp_manifest_ready($driftRoot);
            if (file_put_contents($path, $original, LOCK_EX) === false || !qd_mp_manifest_ready($driftRoot)) throw new RuntimeException('Could not restore a drift fixture.');
            $checks['everyPinDriftRefused'] = $checks['everyPinDriftRefused'] && $refused;
        }
    }
} catch (Throwable $error) {
    $errorReason = 'source_contract_probe_failed';
    fwrite(STDERR, 'MP source-contract probe failed: ' . get_class($error) . "\n");
} finally {
    if ($tempRoot !== null && $tempOwned) {
        try { mpSourceCleanup($tempRoot); }
        catch (Throwable $error) { $errorReason = 'temporary_fixture_cleanup_failed'; fwrite(STDERR, 'MP source-contract cleanup failed: ' . get_class($error) . "\n"); }
    }
}
$ready = $errorReason === null && !in_array(false, $checks, true);
if (!$ready && $errorReason === null) $errorReason = 'source_contract_check_failed';
$receipt = [
    'ready' => $ready,
    'errorReason' => $errorReason,
    'owner' => ['version' => $ownerVersion, 'selectedClosurePins' => $pins],
    'checks' => $checks,
    'limits' => ['sourceOnly' => true, 'database' => false, 'http' => false, 'skyrim' => false, 'ownerRuntimeLoaded' => false, 'ownerPureReadersLoaded' => $ownerPureReadersLoaded],
    'department' => [
        'protocol' => ['file' => 'lib/protocol.php', 'sha256' => mpSourceHash(is_string($protocolReal) ? $protocolReal : '')],
        'adapter' => ['file' => 'adapters/mp.php', 'sha256' => mpSourceHash(is_string($adapterReal) ? $adapterReal : '')],
    ],
];
echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($ready ? 0 : 1);
