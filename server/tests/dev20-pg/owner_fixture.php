<?php
declare(strict_types=1);

/** Small fixture entry for the pinned owner lifecycle; never used by the API driver. */
function qd_dev20_fixture_fail(string $code): never
{
    fwrite(STDERR, 'dev20-owner-fixture-failure:' . $code . PHP_EOL);
    exit(2);
}

function qd_dev20_owner_role(mixed $connection, bool $reset = false): void
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

function qd_dev20_make_private_file_readable_by_api(string $path): void
{
    foreach ([$path, $path . '.lock'] as $privatePath) {
        if (!is_file($privatePath) || is_link($privatePath)) throw new RuntimeException('owner_fixture_private_file_invalid');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0
            && (!@chown($privatePath, 'www-data') || !@chgrp($privatePath, 'www-data'))) {
            throw new RuntimeException('owner_fixture_private_file_owner_failed');
        }
        if (!@chmod($privatePath, 0660)) throw new RuntimeException('owner_fixture_private_file_mode_failed');
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'fixture_guard.php';
try {
    $fixtureIdentity = qd_dev20_assert_private_fixture();
} catch (Throwable) {
    qd_dev20_fixture_fail('private_fixture_guard_refused');
}

$options = getopt('', ['mode:', 'core-root:', 'pg-socket:', 'release-file:', 'operation-id:', 'stale-epoch:', 'start-rowid:', 'native-ts:', 'gamets:']);
$mode = is_array($options) ? ($options['mode'] ?? null) : null;
$coreArgument = is_array($options) ? ($options['core-root'] ?? null) : null;
$socketArgument = is_array($options) ? ($options['pg-socket'] ?? null) : null;
$releaseFile = is_array($options) ? ($options['release-file'] ?? null) : null;
$operationId = is_array($options) ? ($options['operation-id'] ?? null) : null;
$staleEpoch = is_array($options) ? ($options['stale-epoch'] ?? null) : null;
$startRowId = is_array($options) ? ($options['start-rowid'] ?? null) : null;
$nativeTs = is_array($options) ? ($options['native-ts'] ?? null) : null;
$gamets = is_array($options) ? ($options['gamets'] ?? null) : null;
$modes = ['initialize', 'seed-presence', 'rotate-epoch', 'prepare-init', 'prepare-init-hold', 'complete-init', 'complete-stale-init', 'operator-close'];
if (!in_array($mode, $modes, true) || !is_string($coreArgument) || $coreArgument === ''
    || !is_string($socketArgument) || $socketArgument === ''
    || (in_array($mode, ['rotate-epoch', 'prepare-init-hold'], true) && !is_string($releaseFile))
    || ($mode === 'rotate-epoch' && $releaseFile !== getenv('QD_DEV20_SCRATCH_ROOT') . '/control/epoch-rotate-release')
    || ($mode === 'prepare-init-hold' && $releaseFile !== getenv('QD_DEV20_SCRATCH_ROOT') . '/control/init-a-release')
    || (in_array($mode, ['operator-close'], true) && (!is_string($operationId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $operationId) !== 1))
    || ($mode === 'complete-stale-init' && (!is_string($staleEpoch) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $staleEpoch) !== 1
        || !is_string($startRowId) || !ctype_digit($startRowId) || (int)$startRowId < 1 || !is_string($gamets) || !ctype_digit($gamets)))
    || (in_array($mode, ['prepare-init', 'prepare-init-hold', 'complete-init'], true)
        && (($nativeTs !== null && (!is_string($nativeTs) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $nativeTs) !== 1))
            || ($gamets !== null && (!is_string($gamets) || preg_match('/\A[0-9]{1,20}\z/D', $gamets) !== 1))))) {
    qd_dev20_fixture_fail('arguments_invalid');
}

$coreRoot = realpath($coreArgument);
$ownerBootstrap = is_string($coreRoot) ? $coreRoot . '/ext/correspondence/lib/bootstrap.php' : '';
if (!is_string($coreRoot) || !is_dir($coreRoot) || is_link($coreArgument)
    || $coreArgument !== ($fixtureIdentity['coreRoot'] ?? null) || $coreRoot !== ($fixtureIdentity['coreRoot'] ?? null)
    || !is_file($ownerBootstrap) || is_link($ownerBootstrap)) {
    qd_dev20_fixture_fail('pinned_owner_missing');
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
            qd_dev20_owner_role($connection);
            $store->ensureSchema();
            $result = @pg_query_params($connection, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE', []);
            $state = $result === false ? [] : (pg_fetch_all($result) ?: []);
            if ($result !== false) pg_free_result($result);
            if (count($state) !== 1 || !is_string($state[0]['epoch'] ?? null)) {
                throw new RuntimeException('owner_restore_state_unavailable');
            }
            if (($state[0]['pending'] ?? null) === 't') {
                if (!CorrespondencePgStore::writeRestoreSentinelFile($sentinel, 'pending', $state[0]['epoch'])) {
                    throw new RuntimeException('owner_restore_pending_sentinel_write_failed');
                }
                $store->completeRestoreEpoch($state[0]['epoch']);
            }
            $result = @pg_query_params($connection, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE', []);
            $verified = $result === false ? [] : (pg_fetch_all($result) ?: []);
            if ($result !== false) pg_free_result($result);
            if (count($verified) !== 1 || ($verified[0]['pending'] ?? null) !== 'f'
                || !CorrespondencePgStore::writeRestoreSentinelFile($sentinel, 'clear', $verified[0]['epoch'])) {
                throw new RuntimeException('owner_restore_state_not_ready');
            }
            qd_dev20_make_private_file_readable_by_api($sentinel);
            qd_dev20_owner_role($connection, true);
            foreach ([
                'GRANT USAGE, CREATE ON SCHEMA plugins TO dwemer',
                'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA plugins TO dwemer',
            ] as $grant) {
                if (@pg_query($connection, $grant) === false) throw new RuntimeException('owner_fixture_grant_failed');
            }
        } elseif ($mode === 'seed-presence') {
            qd_dev20_owner_role($connection);
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
            qd_dev20_owner_role($connection, true);
        } elseif (in_array($mode, ['prepare-init', 'prepare-init-hold', 'complete-init'], true)) {
            $nativeTs ??= (string)time();
            if ($gamets === null) {
                $clockResult = @pg_query($connection, 'SELECT COALESCE(MAX(gamets),0)::text AS gamets FROM public.eventlog');
                $clock = $clockResult === false ? [] : (pg_fetch_assoc($clockResult) ?: []);
                if ($clockResult !== false) pg_free_result($clockResult);
                if (!ctype_digit((string)($clock['gamets'] ?? ''))) throw new RuntimeException('restore_init_clock_unavailable');
                $gamets = (string)((int)$clock['gamets'] + 1);
            }
            if (preg_match('/\A[0-9]{1,20}\z/D', $gamets) !== 1) throw new RuntimeException('restore_init_gamets_invalid');
            $request = [0 => 'inputtext', 1 => $nativeTs, 2 => $gamets];
            correspondenceBeginRequestIngress($request);
            correspondencePrepareRestoreInit($request);
            $initEpoch = $GLOBALS['CORRESPONDENCE_RESTORE_INIT_EPOCH'] ?? null;
            $initStart = $GLOBALS['CORRESPONDENCE_RESTORE_INIT_ROWID'] ?? null;
            if (!is_string($initEpoch) || !is_int($initStart)) throw new RuntimeException('restore_init_prepare_missing_receipt');
            if ($mode === 'prepare-init-hold') {
                fwrite(STDOUT, json_encode(['event' => 'init_prepared', 'epoch' => $initEpoch, 'rowId' => $initStart,
                    'gamets' => $gamets, 'unsafe' => !empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE'])], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
                fflush(STDOUT);
                $deadline = microtime(true) + 45;
                while (!is_file($releaseFile)) {
                    if (microtime(true) >= $deadline) throw new RuntimeException('restore_init_release_timeout');
                    usleep(10000);
                }
                if (is_link($releaseFile)) throw new RuntimeException('restore_init_release_redirected');
            } elseif ($mode === 'complete-init') {
                $markerData = 'dev20-init:' . ($fixtureIdentity['runId'] ?? '');
                $marker = @pg_query_params($connection,
                    "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1::bigint,$2::numeric,'init',$3,'dev20_init_test',$1::bigint,'','','') RETURNING rowid::text",
                    [$nativeTs, $gamets, $markerData]);
                if ($marker === false) throw new RuntimeException('restore_init_marker_insert_failed');
                try { $markerRow = pg_fetch_assoc($marker) ?: []; } finally { pg_free_result($marker); }
                if (!ctype_digit((string)($markerRow['rowid'] ?? '')) || (int)$markerRow['rowid'] <= $initStart) {
                    throw new RuntimeException('restore_init_marker_not_after_start');
                }
                $GLOBALS['MUST_END'] = true;
                $completed = correspondenceCompletePendingInit();
                if (!$completed) throw new RuntimeException('restore_init_completion_refused');
                $markerId = (int)$markerRow['rowid'];
            }
            qd_dev20_owner_role($connection, true);
        } elseif ($mode === 'complete-stale-init') {
            qd_dev20_owner_role($connection);
            $runtime = correspondenceProduction();
            $store = $runtime['store'];
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_ACTIVE'] = true;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_EPOCH'] = $staleEpoch;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_STORE'] = $store;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_ROWID'] = (int)$startRowId;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_GAMETS'] = $gamets;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_FENCE_OK'] = true;
            $GLOBALS['CORRESPONDENCE_RESTORE_INIT_CLOCK_VALID'] = true;
            $GLOBALS['MUST_END'] = true;
            $staleCompleted = correspondenceCompletePendingInit();
            qd_dev20_owner_role($connection, true);
        } elseif ($mode === 'operator-close') {
            qd_dev20_owner_role($connection);
            $store = new CorrespondencePgStore($connection,
                static fn($candidate): ?string => $candidate->observedExecutor(),
                static fn(string $title, string $body): bool => correspondencePrepareCoreNote($coreRoot, $title, $body),
                $sentinel);
            $store->ensureSchema();
            correspondenceBeginRequestIngress([]);
            correspondenceCaptureRequestIngress($store);
            $scope = $store->scope();
            $playthroughId = $scope['playthrough_id'] ?? null;
            if (!is_string($playthroughId) || $playthroughId === '') throw new RuntimeException('operator_close_scope_unavailable');
            $document = $store->findRequest($playthroughId, $operationId);
            if (!is_array($document) || !is_string($document['document_id'] ?? null)) throw new RuntimeException('operator_close_document_not_found');
            $closed = (new CorrespondenceService($store))->close($document['document_id']);
            $closedDocument = $store->loadDocument($document['document_id']);
            if (!is_array($closedDocument) || !is_array($closedDocument['operator_close'] ?? null)) throw new RuntimeException('operator_close_document_not_persisted');
            $closeReceipt = ['result' => $closed, 'documentId' => $document['document_id'], 'operationId' => $operationId,
                'closeEvent' => $closedDocument['operator_close']];
            qd_dev20_owner_role($connection, true);
        } elseif ($mode === 'rotate-epoch') {
            $locked = @pg_query_params($connection, 'SELECT pg_advisory_lock(18459814,1)', []);
            if ($locked === false) throw new RuntimeException('owner_fixture_lock_failed');
            pg_free_result($locked);
            fwrite(STDOUT, json_encode(['schema' => 'department.dev20.owner-fixture.v1', 'mode' => $mode, 'event' => 'exclusive_acquired'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            fflush(STDOUT);
            $deadline = microtime(true) + 45;
            while (!is_file($releaseFile)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('owner_fixture_release_timeout');
                usleep(10000);
            }
            if (is_link($releaseFile)) throw new RuntimeException('owner_fixture_release_redirected');
            qd_dev20_owner_role($connection);
            $store->ensureSchema();
            $epoch = correspondenceUuid();
            if (!CorrespondencePgStore::writeRestoreSentinelFile($sentinel, 'pending', $epoch)) {
                throw new RuntimeException('owner_restore_pending_sentinel_write_failed');
            }
            $store->beginRestoreEpoch($epoch, '0');
            $store->completeRestoreEpoch($epoch);
            qd_dev20_make_private_file_readable_by_api($sentinel);
            qd_dev20_owner_role($connection, true);
            $unlocked = @pg_query_params($connection, 'SELECT pg_advisory_unlock(18459814,1) AS unlocked', []);
            if ($unlocked === false) throw new RuntimeException('owner_fixture_unlock_failed');
            $unlockedRow = pg_fetch_assoc($unlocked);
            pg_free_result($unlocked);
            if (($unlockedRow['unlocked'] ?? null) !== 't') throw new RuntimeException('owner_fixture_unlock_failed');
        }

        $extraReceipt = [];
        if ($mode === 'prepare-init' || $mode === 'prepare-init-hold') {
            $extraReceipt = ['initEpoch' => $initEpoch, 'initStartRowId' => $initStart, 'unsafe' => !empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']),
                'sentinel' => is_file($sentinel) ? json_decode((string)file_get_contents($sentinel), true) : null];
        } elseif ($mode === 'complete-init') {
            qd_dev20_make_private_file_readable_by_api($sentinel);
            $stateResult = @pg_query($connection, 'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            $state = $stateResult === false ? [] : (pg_fetch_all($stateResult) ?: []);
            if ($stateResult !== false) pg_free_result($stateResult);
            $extraReceipt = ['initEpoch' => $initEpoch, 'initStartRowId' => $initStart, 'markerRowId' => $markerId ?? null,
                'sentinel' => is_file($sentinel) ? json_decode((string)file_get_contents($sentinel), true) : null, 'restoreState' => $state[0] ?? null];
        } elseif ($mode === 'complete-stale-init') {
            $stateResult = @pg_query($connection, 'SELECT epoch::text,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            $state = $stateResult === false ? [] : (pg_fetch_all($stateResult) ?: []);
            if ($stateResult !== false) pg_free_result($stateResult);
            $extraReceipt = ['staleEpoch' => $staleEpoch, 'staleCompletionAccepted' => $staleCompleted,
                'sentinel' => is_file($sentinel) ? json_decode((string)file_get_contents($sentinel), true) : null, 'restoreState' => $state[0] ?? null];
        } elseif ($mode === 'operator-close') {
            $extraReceipt = $closeReceipt;
        }
        fwrite(STDOUT, json_encode([
            'schema' => 'department.dev20.owner-fixture.v1',
            'mode' => $mode,
            'effectiveOwnerRole' => $effectiveOwnerRole,
            'presenceExecutor' => $presenceExecutor,
            'ready' => true,
        ] + $extraReceipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    } finally {
        try { @pg_close($connection); } catch (Throwable) {}
    }
} catch (Throwable $error) {
    $diagnostic = '';
    if ($error instanceof DomainException && preg_match('/\A[a-z0-9_]{1,80}\z/D', $error->getMessage()) === 1) {
        $diagnostic = '_domain_' . $error->getMessage();
    }
    qd_dev20_fixture_fail('runtime_' . strtolower((new ReflectionClass($error))->getShortName()) . $diagnostic);
}
