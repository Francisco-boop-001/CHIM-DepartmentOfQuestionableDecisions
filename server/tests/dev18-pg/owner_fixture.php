<?php
declare(strict_types=1);

/** Small fixture entry for the pinned owner lifecycle; never used by the API driver. */
function qd_dev18_fixture_fail(string $code): never
{
    fwrite(STDERR, 'dev18-owner-fixture-failure:' . $code . PHP_EOL);
    exit(2);
}

function qd_dev18_owner_role(mixed $connection, bool $reset = false): void
{
    $sql = $reset ? 'RESET ROLE' : 'SET ROLE dwemer';
    $result = @pg_query($connection, $sql);
    if ($result === false) throw new RuntimeException('owner_fixture_role_switch_failed');
    pg_free_result($result);
    if (!$reset) {
        $roleResult = @pg_query($connection, 'SELECT current_user');
        $row = $roleResult === false ? [] : (pg_fetch_assoc($roleResult) ?: []);
        if ($roleResult !== false) pg_free_result($roleResult);
        if (($row['current_user'] ?? null) !== 'dwemer') throw new RuntimeException('owner_fixture_role_not_app');
    }
}

function qd_dev18_make_private_file_readable_by_api(string $path): void
{
    if (!is_file($path) || is_link($path)) throw new RuntimeException('owner_fixture_private_file_invalid');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($path, 'www-data') || !@chgrp($path, 'www-data'))) {
        throw new RuntimeException('owner_fixture_private_file_owner_failed');
    }
    if (!@chmod($path, 0660)) throw new RuntimeException('owner_fixture_private_file_mode_failed');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'fixture_guard.php';
try {
    $fixtureIdentity = qd_dev18_assert_private_fixture();
} catch (Throwable) {
    qd_dev18_fixture_fail('private_fixture_guard_refused');
}

$options = getopt('', ['mode:', 'core-root:', 'pg-socket:', 'release-file:']);
$mode = is_array($options) ? ($options['mode'] ?? null) : null;
$coreArgument = is_array($options) ? ($options['core-root'] ?? null) : null;
$socketArgument = is_array($options) ? ($options['pg-socket'] ?? null) : null;
$releaseFile = is_array($options) ? ($options['release-file'] ?? null) : null;
if (!in_array($mode, ['initialize', 'seed-presence', 'rotate-epoch'], true) || !is_string($coreArgument) || $coreArgument === ''
    || !is_string($socketArgument) || $socketArgument === ''
    || ($mode === 'rotate-epoch' && (!is_string($releaseFile) || $releaseFile !== getenv('QD_DEV18_SCRATCH_ROOT') . '/control/epoch-rotate-release'))) {
    qd_dev18_fixture_fail('arguments_invalid');
}

$coreRoot = realpath($coreArgument);
$ownerBootstrap = is_string($coreRoot) ? $coreRoot . '/ext/correspondence/lib/bootstrap.php' : '';
if (!is_string($coreRoot) || !is_dir($coreRoot) || is_link($coreArgument)
    || $coreArgument !== ($fixtureIdentity['coreRoot'] ?? null) || $coreRoot !== ($fixtureIdentity['coreRoot'] ?? null)
    || !is_file($ownerBootstrap) || is_link($ownerBootstrap)) {
    qd_dev18_fixture_fail('pinned_owner_missing');
}

try {
    $GLOBALS['ENGINE_PATH'] = $coreRoot;
    require_once $coreRoot . '/lib/playthrough_runtime.php';
    require_once $ownerBootstrap;
    $socket = realpath($socketArgument);
    $expectedSocket = ($fixtureIdentity['scratchRoot'] ?? '') . '/socket';
    if (!is_string($socket) || !is_dir($socket) || is_link($socketArgument)
        || $socketArgument !== $expectedSocket || $socket !== $expectedSocket) throw new RuntimeException('private_socket_invalid');
    ptr_runtime_enter();
    $workLock = $coreRoot . '/log/playthrough_runtime/work.lock';
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!is_file($workLock) || !@chown($workLock, 'www-data') || !@chgrp($workLock, 'www-data') || !@chmod($workLock, 0660))) {
        throw new RuntimeException('private_work_lock_owner_failed');
    }
    $generation = $coreRoot . '/log/playthrough_runtime/generation';
    if (is_file($generation) && function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($generation, 'www-data') || !@chgrp($generation, 'www-data') || !@chmod($generation, 0660))) {
        throw new RuntimeException('private_generation_owner_failed');
    }
    $connection = @pg_connect("host='" . str_replace(["\\", "'"], ["\\\\", "\\'"], $socket) . "' port=5432 dbname=dwemer user=postgres connect_timeout=3", PGSQL_CONNECT_FORCE_NEW);
    if ($connection === false || $connection === null) throw new RuntimeException('owner_connection_unavailable');
    $presenceExecutor = null;
    try {
        $sentinel = $coreRoot . '/log/playthrough_runtime/correspondence.restore.json';
    $effectiveOwnerRole = 'dwemer';
        $store = new CorrespondencePgStore(
            $connection,
            static fn($candidate): ?string => $candidate->observedExecutor(),
            static fn(string $title, string $body): bool => correspondencePrepareCoreNote($coreRoot, $title, $body),
            $sentinel,
        );
        if ($mode === 'initialize') {
            qd_dev18_owner_role($connection);
            $store->ensureSchema();
            $result = @pg_query_params($connection, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE', []);
            $state = $result === false ? [] : (pg_fetch_all($result) ?: []);
            if ($result !== false) pg_free_result($result);
            if (count($state) !== 1 || !is_string($state[0]['epoch'] ?? null)) {
                throw new RuntimeException('owner_restore_state_unavailable');
            }
            if (($state[0]['pending'] ?? null) === 't') {
                $store->completeRestoreEpoch($state[0]['epoch']);
            }
            $result = @pg_query_params($connection, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE', []);
            $verified = $result === false ? [] : (pg_fetch_all($result) ?: []);
            if ($result !== false) pg_free_result($result);
            if (count($verified) !== 1 || ($verified[0]['pending'] ?? null) !== 'f'
                || !CorrespondencePgStore::writeRestoreSentinelFile($sentinel, 'clear', $verified[0]['epoch'])) {
                throw new RuntimeException('owner_restore_state_not_ready');
            }
            qd_dev18_make_private_file_readable_by_api($sentinel);
            qd_dev18_owner_role($connection, true);
            foreach ([
                'GRANT USAGE, CREATE ON SCHEMA plugins TO dwemer',
                'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA plugins TO dwemer',
            ] as $grant) {
                if (@pg_query($connection, $grant) === false) throw new RuntimeException('owner_fixture_grant_failed');
            }
        } elseif ($mode === 'seed-presence') {
            qd_dev18_owner_role($connection);
            $store->ensureSchema();
            correspondenceBeginRequestIngress([]);
            correspondenceCaptureRequestIngress($store);
            $clock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
            if (count($clock) !== 1 || !is_string($clock[0]['ts'] ?? null) || !is_string($clock[0]['gamets'] ?? null)) {
                throw new RuntimeException('owner_fixture_request_clock_unavailable');
            }
            $store->bindRequestClock([0 => 'inputtext', 1 => $clock[0]['ts'], 2 => $clock[0]['gamets']]);
            $raw = base64_encode(json_encode([
                'source' => 'plugin_player_routing_v2',
                'present_actors' => [[
                    'form_id' => hexdec('000A2C94'),
                    'name' => 'Lydia',
                    'managed' => true,
                    'creature' => false,
                    'distance' => 0,
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $store->observeManaged($raw);
            $presenceExecutor = $store->observedExecutor();
            if ($presenceExecutor !== 'Lydia') throw new RuntimeException('owner_fixture_presence_executor_not_lydia');
            qd_dev18_owner_role($connection, true);
        } else {
            $locked = @pg_query_params($connection, 'SELECT pg_advisory_lock(18459814,1)', []);
            if ($locked === false) throw new RuntimeException('owner_fixture_lock_failed');
            pg_free_result($locked);
            fwrite(STDOUT, json_encode(['schema' => 'department.dev18.owner-fixture.v1', 'mode' => $mode, 'event' => 'exclusive_acquired'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            fflush(STDOUT);
            $deadline = microtime(true) + 45;
            while (!is_file($releaseFile)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('owner_fixture_release_timeout');
                usleep(10000);
            }
            if (is_link($releaseFile)) throw new RuntimeException('owner_fixture_release_redirected');
            qd_dev18_owner_role($connection);
            $store->ensureSchema();
            $epoch = $store->beginRestoreEpoch(null, '0');
            $store->completeRestoreEpoch($epoch);
            qd_dev18_make_private_file_readable_by_api($sentinel);
            qd_dev18_owner_role($connection, true);
            $unlocked = @pg_query_params($connection, 'SELECT pg_advisory_unlock(18459814,1) AS unlocked', []);
            if ($unlocked === false) throw new RuntimeException('owner_fixture_unlock_failed');
            $unlockedRow = pg_fetch_assoc($unlocked);
            pg_free_result($unlocked);
            if (($unlockedRow['unlocked'] ?? null) !== 't') throw new RuntimeException('owner_fixture_unlock_failed');
        }

        fwrite(STDOUT, json_encode([
            'schema' => 'department.dev18.owner-fixture.v1',
            'mode' => $mode,
            'effectiveOwnerRole' => $effectiveOwnerRole,
            'presenceExecutor' => $presenceExecutor,
            'ready' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    } finally {
        try { @pg_close($connection); } catch (Throwable) {}
    }
} catch (Throwable $error) {
    qd_dev18_fixture_fail('runtime_' . strtolower((new ReflectionClass($error))->getShortName()));
}
