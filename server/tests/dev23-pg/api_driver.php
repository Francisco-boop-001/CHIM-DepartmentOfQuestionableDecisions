<?php
declare(strict_types=1);

/**
 * One real Department HTTP-facade request per process.
 *
 * The caller owns a fresh --fixture-root and supplies one JSON request on STDIN:
 * {"operation":"correspondence.mailbox.read","payload":{"recipientActorKey":"npc:900003"}}
 * Optional keys: caseId, authScopes, pauseAfterProfileLease, sourceDrift,
 * pauseTimeoutSeconds, nestedProfileLease, priorRequestGlobals, leaseMode,
 * operationRequestId, repeatSameRequest.
 * Tokens, server contexts, payloads, and private paths are never written to stdout.
 */
function qd_dev20_fail(string $code): never
{
    if (!empty($GLOBALS['qdDev18CleanupActive'])) {
        throw new QDDev18DriverFailure($code);
    }
    fwrite(STDERR, "dev20-api-driver-failure:" . $code . PHP_EOL);
    exit(2);
}

final class QDDev18DriverFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}

function qd_dev20_emit(array $value): void
{
    $line = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $line . PHP_EOL);
    fflush(STDOUT);
}

function qd_dev20_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function qd_dev20_snapshot_globals(array $names): array
{
    $snapshot = [];
    foreach ($names as $name) {
        $snapshot[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
    }
    return $snapshot;
}

function qd_dev20_globals_match(array $snapshot): bool
{
    foreach ($snapshot as $name => [$wasPresent, $oldValue]) {
        $isPresent = array_key_exists($name, $GLOBALS);
        if ($isPresent !== $wasPresent) return false;
        if ($wasPresent && $GLOBALS[$name] !== $oldValue) return false;
    }
    return true;
}

function qd_dev20_summary(array $response, string $phase): array
{
    $wire = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($wire)) {
        return ['event' => 'api_response', 'phase' => $phase, 'httpStatus' => $response['status'] ?? null, 'validJson' => false];
    }
    $summary = [
        'event' => 'api_response',
        'phase' => $phase,
        'httpStatus' => $response['status'] ?? null,
        'ok' => ($wire['ok'] ?? false) === true,
    ];
    if (is_string($wire['requestId'] ?? null)) {
        $summary['requestId'] = $wire['requestId'];
    }
    if (($wire['ok'] ?? false) === true && is_array($wire['data'] ?? null)) {
        if ($phase === 'bootstrap') {
            $data = $wire['data'];
            $summary['data'] = [
                'authenticated' => $data['authenticated'] ?? null,
                'authRequired' => $data['authRequired'] ?? null,
                'capabilities' => $data['capabilities'] ?? null,
                'access' => $data['access'] ?? null,
            ];
        } else {
            $summary['data'] = $wire['data'];
        }
    } elseif (is_array($wire['error'] ?? null)) {
        $summary['errorCode'] = $wire['error']['code'] ?? null;
    }
    return $summary;
}

function qd_dev20_real_generation(string $coreRoot): string
{
    $runtimeRoot = $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime';
    $path = $runtimeRoot . DIRECTORY_SEPARATOR . 'generation';
    if (is_link($runtimeRoot) || is_link($path)) throw new RuntimeException('invalid_generation_path');
    if (!file_exists($path)) return '';
    if (!is_file($path)) throw new RuntimeException('invalid_generation_file');
    $raw = file_get_contents($path, false, null, 0, 257);
    if (!is_string($raw) || strlen($raw) > 256) throw new RuntimeException('invalid_generation_size');
    $generation = trim($raw);
    if (preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) throw new RuntimeException('invalid_generation_value');
    return $generation;
}

/** Seed a captured ingress only from the isolated database and the real core file. */
function qd_dev20_current_restore_state(): array
{
    if (!function_exists('ptp_connect')) qd_runtime_load_core_readers();
    $connection = ptp_connect();
    if ($connection === false) throw new RuntimeException('restore_state_connection_failed');
    try {
        $result = @pg_query_params($connection,
            'SELECT epoch::text,pending,native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE', []);
        if ($result === false) throw new RuntimeException('restore_state_query_failed');
        try {
            $rows = pg_fetch_all($result) ?: [];
        } finally {
            pg_free_result($result);
        }
        if (count($rows) !== 1 || ($rows[0]['pending'] ?? null) !== 'f'
            || !is_string($rows[0]['epoch'] ?? null)
            || !is_string($rows[0]['native_ts_floor'] ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $rows[0]['native_ts_floor']) !== 1) {
            throw new RuntimeException('restore_state_not_ready');
        }
        $generation = qd_runtime_correspondence_read_generation();
        return [
            'token' => $rows[0]['epoch'] . ':' . ($generation === '' ? 'none' : $generation),
            'floor' => $rows[0]['native_ts_floor'],
        ];
    } finally {
        @pg_close($connection);
    }
}

function qd_dev20_lease_still_held(string $path): bool
{
    $probe = @fopen($path, 'c+b');
    if (!is_resource($probe)) return false;
    try {
        if (@flock($probe, LOCK_EX | LOCK_NB)) {
            @flock($probe, LOCK_UN);
            return false;
        }
        return true;
    } finally {
        @fclose($probe);
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'fixture_guard.php';
try {
    $fixtureIdentity = qd_dev20_assert_private_fixture();
} catch (Throwable $error) {
    $detail = $error->getMessage();
    if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/iD', $detail) !== 1) $detail = 'detail_unavailable';
    qd_dev20_fail('private_fixture_guard_refused_' . strtolower($detail));
}

$options = getopt('', ['fixture-root:']);
if (!is_array($options) || !is_string($options['fixture-root'] ?? null) || $options['fixture-root'] === '') {
    qd_dev20_fail('fixture_root_required');
}
$fixtureArgument = $options['fixture-root'];
$rawInput = stream_get_contents(STDIN, 65537);
if (!is_string($rawInput) || $rawInput === '' || strlen($rawInput) > 65536) {
    qd_dev20_fail('request_input_invalid');
}
try {
    $input = json_decode($rawInput, false, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    qd_dev20_fail('request_json_invalid');
}
if (!is_object($input) || !is_string($input->operation ?? null) || !is_object($input->payload ?? null)) {
    qd_dev20_fail('request_shape_invalid');
}
$allowed = [
    'bootstrap',
    'correspondence.catalog.read',
    'correspondence.mailbox.read',
    'correspondence.detail.read',
    'correspondence.compose',
    'correspondence.reconcile',
];
if (!in_array($input->operation, $allowed, true)) {
    qd_dev20_fail('operation_not_allowlisted');
}
$caseId = is_string($input->caseId ?? null) && preg_match('/\A[a-z0-9_-]{1,64}\z/D', $input->caseId) === 1
    ? $input->caseId : null;
if ($caseId === null) qd_dev20_fail('case_id_invalid');
$expectedFixtureParent = $fixtureIdentity['scratchRoot'] . DIRECTORY_SEPARATOR . 'api-fixtures';
$fixtureParent = realpath(dirname($fixtureArgument));
if (!is_string($fixtureParent) || $fixtureParent !== $expectedFixtureParent
    || dirname($fixtureArgument) !== $expectedFixtureParent || !is_dir($expectedFixtureParent)
    || is_link($expectedFixtureParent) || basename($fixtureArgument) !== $caseId
    || $fixtureArgument !== $expectedFixtureParent . DIRECTORY_SEPARATOR . $caseId
    || file_exists($fixtureArgument) || is_link($fixtureArgument)) {
    qd_dev20_fail('fixture_root_not_fresh');
}
$fixtureRoot = $expectedFixtureParent . DIRECTORY_SEPARATOR . $caseId;
if (!@mkdir($fixtureRoot, 0700) || !@mkdir($fixtureRoot . DIRECTORY_SEPARATOR . 'web', 0700)
    || !@mkdir($fixtureRoot . DIRECTORY_SEPARATOR . 'private', 0700)
    || !@mkdir($fixtureRoot . DIRECTORY_SEPARATOR . 'control', 0700)) {
    qd_dev20_fail('fixture_root_create_failed');
}
$scopes = $input->authScopes ?? ['inspector', 'compose'];
if (!is_array($scopes) || !array_is_list($scopes)) {
    qd_dev20_fail('scopes_invalid');
}
$pause = ($input->pauseAfterProfileLease ?? false) === true;
$pauseTimeout = $input->pauseTimeoutSeconds ?? 90;
if (!is_int($pauseTimeout) || $pauseTimeout < 1 || $pauseTimeout > 300) {
    qd_dev20_fail('pause_timeout_invalid');
}
$nestedLease = ($input->nestedProfileLease ?? false) === true;
$priorRequestGlobals = $input->priorRequestGlobals ?? 'absent';
if (!in_array($priorRequestGlobals, ['absent', 'captured-invalid', 'captured-null-token', 'captured-current', 'captured-current-above-floor', 'captured-current-at-floor', 'captured-current-stale-generation', 'uncaptured-valid-ts', 'uncaptured-malformed-ts', 'uncaptured-token'], true)) {
    qd_dev20_fail('prior_request_globals_invalid');
}
$leaseMode = $input->leaseMode ?? 'qd-owned';
if (!in_array($leaseMode, ['qd-owned', 'main-owned', 'main-owned-replaced'], true)) {
    qd_dev20_fail('lease_mode_invalid');
}
$mainLeaseGeneration = $input->mainLeaseGeneration ?? 'actual';
if (!in_array($mainLeaseGeneration, ['actual', 'mismatch'], true)) {
    qd_dev20_fail('main_lease_generation_invalid');
}
$operationRequestId = $input->operationRequestId ?? null;
if ($operationRequestId !== null
    && (!is_string($operationRequestId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $operationRequestId) !== 1)) {
    qd_dev20_fail('operation_request_id_invalid');
}
$repeatSameRequest = ($input->repeatSameRequest ?? false) === true;
$sourceDrift = ($input->sourceDrift ?? false) === true;
if ($sourceDrift && $input->operation !== 'bootstrap') qd_dev20_fail('source_drift_bootstrap_only');

$chimRoot = getenv('QD_CHIM_ROOT');
if (!is_string($chimRoot) || $chimRoot === '' || !is_dir($chimRoot) || !is_dir($chimRoot . DIRECTORY_SEPARATOR . 'lib')) {
    qd_dev20_fail('private_chim_clone_missing');
}
$canonicalChimRoot = realpath($chimRoot);
if (!is_string($canonicalChimRoot)) qd_dev20_fail('private_chim_clone_unresolved');

$serverRoot = dirname(__DIR__, 2);
$apiPath = $serverRoot . DIRECTORY_SEPARATOR . 'api.php';
if (!is_file($apiPath) || is_link($apiPath)) {
    qd_dev20_fail('api_source_missing');
}
$trackedGlobals = [
    'ptr_runtime_lease',
    'ptr_runtime_generation',
    'ENGINE_PATH',
    'CORRESPONDENCE_REQUEST_RESTORE_CAPTURED',
    'CORRESPONDENCE_REQUEST_RESTORE_TOKEN',
    'CORRESPONDENCE_REQUEST_NATIVE_TS',
];
$mainLease = null;
$mainLeaseLockOwned = false;
$mainLeasePath = null;
$mainLeaseOriginalPath = null;
$globalSnapshot = null;
$driverFailed = false;
$GLOBALS['qdDev18CleanupActive'] = true;
try {
    require_once $apiPath;
    $fixtureWeb = $fixtureRoot . DIRECTORY_SEPARATOR . 'web';
    $fixturePrivate = $fixtureRoot . DIRECTORY_SEPARATOR . 'private';
    $settings = [
        'pairingPath' => $fixturePrivate . DIRECTORY_SEPARATOR . 'pairing.json',
        'statePath' => $fixturePrivate . DIRECTORY_SEPARATOR . 'state.json',
        'webRoot' => $fixtureWeb,
    ];
    $pair = qd_pair_issue($settings['pairingPath'], $settings['webRoot'], $scopes);
    $dependencies = qd_api_runtime_dependencies();
    $realLease = $dependencies['withProfileLease'] ?? null;
    if (!is_callable($realLease)) {
        qd_dev20_fail('real_profile_lease_missing');
    }

    $marker = $fixtureRoot . DIRECTORY_SEPARATOR . 'control' . DIRECTORY_SEPARATOR . 'profile-lease-held';
    $release = $fixtureRoot . DIRECTORY_SEPARATOR . 'control' . DIRECTORY_SEPARATOR . 'continue-request';
    $pauseArmed = false;
    $dependencies['withProfileLease'] = static function (callable $callback) use ($realLease, &$pauseArmed, $marker, $release, $pauseTimeout, $nestedLease, $caseId): mixed {
        $runCallback = static function () use ($callback, &$pauseArmed, $marker, $release, $pauseTimeout, $caseId): mixed {
            if (!$pauseArmed) return $callback();
            $pauseArmed = false;
            $handle = @fopen($marker, 'x+b');
            if ($handle === false) throw new RuntimeException('pause_marker_create_failed');
            fwrite($handle, 'lease-held' . PHP_EOL);
            fflush($handle);
            fclose($handle);
            qd_dev20_emit(['event' => 'profile_lease_paused', 'caseId' => $caseId, 'markerWritten' => true]);
            $deadline = microtime(true) + $pauseTimeout;
            while (!is_file($release)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('pause_barrier_timeout');
                usleep(10000);
            }
            return $callback();
        };
        if ($nestedLease) {
            return $realLease(static fn(): mixed => $realLease($runCallback));
        }
        return $realLease($runCallback);
    };

    $viewToken = qd_dev20_uuid();
    $clientGeneration = '1';
    $authorization = 'Bearer ' . $pair['token'];
    $call = static function (string $operation, object $payload, ?string $serverContext, ?string $requestId = null) use ($viewToken, $clientGeneration, $authorization, $settings, $dependencies): array {
        $request = (object)[
            'version' => 1,
            'requestId' => $requestId ?? qd_dev20_uuid(),
            'operation' => $operation,
            'context' => (object)[
                'viewToken' => $viewToken,
                'nativeGeneration' => $clientGeneration,
                'serverContext' => $serverContext,
            ],
            'payload' => $payload,
        ];
        $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return qd_api_handle('POST', 'application/json', $body, $authorization, $settings, $dependencies);
    };

    $sourceDriftEvidence = null;
    $sourceDriftOperations = [];
    if ($sourceDrift) {
        $target = $canonicalChimRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence'
            . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
        if (is_link($target) || !is_file($target) || realpath(dirname($target)) !== realpath($canonicalChimRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence' . DIRECTORY_SEPARATOR . 'lib')) {
            qd_dev20_fail('source_drift_target_invalid');
        }
        $original = @file_get_contents($target);
        if (!is_string($original)) qd_dev20_fail('source_drift_read_failed');
        $beforeHash = hash('sha256', $original);
        $driftBytes = $original . "\n";
        try {
            if (@file_put_contents($target, $driftBytes, LOCK_EX) !== strlen($driftBytes)) qd_dev20_fail('source_drift_write_failed');
            $driftHash = hash_file('sha256', $target);
            $bootstrap = $call('bootstrap', new stdClass(), null);
            $driftBootstrap = json_decode((string)($bootstrap['body'] ?? ''));
            $serverContext = is_object($driftBootstrap) && ($driftBootstrap->ok ?? false) === true
                && is_object($driftBootstrap->data ?? null) && ($driftBootstrap->data->authenticated ?? false) === true
                && is_string($driftBootstrap->data->serverContext ?? null)
                ? $driftBootstrap->data->serverContext : null;
            if ($serverContext !== null) {
                $operationPayloads = [
                    'correspondence.catalog.read' => new stdClass(),
                    'correspondence.mailbox.read' => (object)['recipientActorKey' => 'npc:900003'],
                    'correspondence.detail.read' => (object)['recipientActorKey' => 'npc:900003', 'letterId' => qd_dev20_uuid()],
                    'correspondence.compose' => (object)[
                        'operationId' => qd_dev20_uuid(), 'writerActorKey' => 'player', 'recipientActorKey' => 'npc:900003',
                        'body' => 'source drift refusal probe', 'inventoryRevision' => 'dev20-drift-probe', 'attachments' => [], 'goldAmount' => 0,
                    ],
                    'correspondence.reconcile' => (object)['operationId' => qd_dev20_uuid()],
                ];
                foreach ($operationPayloads as $operation => $payload) {
                    $response = $call($operation, $payload, $serverContext);
                    $summary = qd_dev20_summary($response, 'source_drift_operation');
                    $summary['operation'] = $operation;
                    $summary['payloadValidated'] = qd_validate_payload($operation, $payload) === 'ok';
                    $summary['authenticatedContextProvided'] = true;
                    $sourceDriftOperations[] = $summary;
                }
            }
        } finally {
            if (@file_put_contents($target, $original, LOCK_EX) !== strlen($original)) qd_dev20_fail('source_drift_restore_failed');
        }
        $restoredHash = hash_file('sha256', $target);
        $sourceDriftEvidence = ['target' => 'ext/correspondence/lib/runtime.php', 'beforeSha256' => $beforeHash,
            'driftSha256' => is_string($driftHash) ? $driftHash : null, 'restoredSha256' => is_string($restoredHash) ? $restoredHash : null,
            'restoredByteExact' => is_string($restoredHash) && hash_equals($beforeHash, $restoredHash),
            'operations' => $sourceDriftOperations];
    } else {
        $bootstrap = $call('bootstrap', new stdClass(), null);
    }
    $bootstrapWire = json_decode((string)($bootstrap['body'] ?? ''), true);
    $bootstrapSummary = qd_dev20_summary($bootstrap, 'bootstrap');
    if (is_array($sourceDriftEvidence)) $bootstrapSummary['sourceDrift'] = $sourceDriftEvidence;
    qd_dev20_emit($bootstrapSummary);
    if ($input->operation === 'bootstrap') exit(0);
    if (!is_array($bootstrapWire) || ($bootstrapWire['ok'] ?? false) !== true
        || !is_array($bootstrapWire['data'] ?? null)
        || ($bootstrapWire['data']['authenticated'] ?? false) !== true
        || !is_string($bootstrapWire['data']['serverContext'] ?? null)) {
        qd_dev20_fail('authenticated_bootstrap_failed');
    }

    if ($leaseMode === 'main-owned' || $leaseMode === 'main-owned-replaced') {
        $lockPath = $canonicalChimRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'work.lock';
        if (is_link($lockPath) || !is_file($lockPath)) qd_dev20_fail('main_lease_path_invalid');
        $mainLease = @fopen($lockPath, 'c+b');
        if (!is_resource($mainLease) || !@flock($mainLease, LOCK_SH | LOCK_NB)) qd_dev20_fail('main_lease_acquire_failed');
        $mainLeaseLockOwned = true;
        $mainLeasePath = $lockPath;
        $GLOBALS['ptr_runtime_lease'] = $mainLease;
        $GLOBALS['ptr_runtime_generation'] = qd_dev20_real_generation($canonicalChimRoot);
        if ($mainLeaseGeneration === 'mismatch') {
            $GLOBALS['ptr_runtime_generation'] = $GLOBALS['ptr_runtime_generation'] === ''
                ? '00000000000000000000000000000000'
                : str_repeat('0', 32);
        }
        $GLOBALS['ENGINE_PATH'] = $canonicalChimRoot;
        if ($leaseMode === 'main-owned-replaced') {
            $mainLeaseOriginalPath = $lockPath . '.dev20-original-' . getmypid();
            if (file_exists($mainLeaseOriginalPath) || is_link($mainLeaseOriginalPath)
                || !@rename($lockPath, $mainLeaseOriginalPath)) qd_dev20_fail('main_lease_path_replace_failed');
            $replacement = @fopen($lockPath, 'x+b');
            if (!is_resource($replacement)) {
                @rename($mainLeaseOriginalPath, $lockPath);
                $mainLeaseOriginalPath = null;
                qd_dev20_fail('main_lease_path_replace_failed');
            }
            @fclose($replacement);
        }
    }
    if ($priorRequestGlobals === 'captured-invalid') {
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = true;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = 'prior-test-token';
        $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
    } elseif ($priorRequestGlobals === 'captured-null-token') {
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = true;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
        $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
    } elseif (in_array($priorRequestGlobals, ['captured-current', 'captured-current-above-floor', 'captured-current-at-floor', 'captured-current-stale-generation'], true)) {
        $restore = qd_dev20_current_restore_state();
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = true;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = $restore['token'];
        if (in_array($priorRequestGlobals, ['captured-current', 'captured-current-stale-generation'], true)) {
            $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
        } elseif ($priorRequestGlobals === 'captured-current-at-floor') {
            $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = $restore['floor'];
        } else {
            $floor = filter_var($restore['floor'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);
            if (!is_int($floor) || $floor >= PHP_INT_MAX) {
                qd_dev20_fail('restore_floor_too_large');
            }
            $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = (string)($floor + 1);
        }
        if ($priorRequestGlobals === 'captured-current-stale-generation') {
            if (is_resource($GLOBALS['ptr_runtime_lease'] ?? null)) qd_dev20_fail('stale_generation_requires_no_lease');
            $diskGeneration = qd_dev20_real_generation($canonicalChimRoot);
            if ($diskGeneration !== str_repeat('a', 32)
                || !str_ends_with($restore['token'], ':' . $diskGeneration)) {
                qd_dev20_fail('stale_generation_fixture_not_current');
            }
            $staleGeneration = str_repeat('b', 32);
            if ($staleGeneration === $diskGeneration) $staleGeneration = str_repeat('c', 32);
            $GLOBALS['ptr_runtime_generation'] = $staleGeneration;
            $fixtureGenerationEvidence = [
                'capturedTokenMatchedDiskGeneration' => true,
                'diskGenerationIsFixtureA' => true,
                'stalePointerGenerationDiffersFromDisk' => true,
                'preexistingLeaseAbsent' => true,
            ];
        }
    } elseif ($priorRequestGlobals === 'uncaptured-valid-ts') {
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
        $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = '17';
    } elseif ($priorRequestGlobals === 'uncaptured-malformed-ts') {
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
        $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = 'not-a-native-timestamp';
    } elseif ($priorRequestGlobals === 'uncaptured-token') {
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = 'prior-test-token';
        $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
    }
    $globalSnapshot = qd_dev20_snapshot_globals($trackedGlobals);
    $borrowedLease = $GLOBALS['ptr_runtime_lease'] ?? null;
    $hadBorrowedLease = is_resource($borrowedLease);
    $pauseArmed = $pause;
    $operationRequestId ??= qd_dev20_uuid();
    $operationResponse = $call($input->operation, $input->payload, $bootstrapWire['data']['serverContext'], $operationRequestId);
    $summary = qd_dev20_summary($operationResponse, 'operation');
    $summary['globalStateRestored'] = qd_dev20_globals_match($globalSnapshot);
    if (isset($fixtureGenerationEvidence)) $summary['fixtureGenerationEvidence'] = $fixtureGenerationEvidence;
    $summary['borrowedLeaseSameOpen'] = !$hadBorrowedLease
        ? !is_resource($GLOBALS['ptr_runtime_lease'] ?? null)
        : (is_resource($GLOBALS['ptr_runtime_lease'] ?? null) && $GLOBALS['ptr_runtime_lease'] === $borrowedLease);
    $summary['borrowedLeaseStillHeld'] = !$hadBorrowedLease
        ? null
        : qd_dev20_lease_still_held(is_string($mainLeaseOriginalPath) ? $mainLeaseOriginalPath : (string)$mainLeasePath);
    if ($caseId !== null) $summary['caseId'] = $caseId;
    qd_dev20_emit($summary);
    if ($repeatSameRequest) {
        $repeatResponse = $call($input->operation, $input->payload, $bootstrapWire['data']['serverContext'], $operationRequestId);
        $repeatSummary = qd_dev20_summary($repeatResponse, 'repeat');
        $repeatSummary['globalStateRestored'] = qd_dev20_globals_match($globalSnapshot);
        if ($caseId !== null) $repeatSummary['caseId'] = $caseId;
        qd_dev20_emit($repeatSummary);
    }
} catch (Throwable $error) {
    if ($error instanceof QDDev18DriverFailure) {
        fwrite(STDERR, 'dev20-api-driver-failure:' . $error->failureCode . PHP_EOL);
    } else {
        fwrite(STDERR, 'dev20-api-driver-failure:runtime_' . strtolower((new ReflectionClass($error))->getShortName()) . PHP_EOL);
    }
    $driverFailed = true;
} finally {
    if (is_resource($mainLease)) {
        if ($mainLeaseLockOwned) @flock($mainLease, LOCK_UN);
        @fclose($mainLease);
    }
    if (is_string($mainLeaseOriginalPath)) {
        $replacementPath = $mainLeasePath;
        if (is_string($replacementPath) && (is_file($replacementPath) || is_link($replacementPath))) @unlink($replacementPath);
        @rename($mainLeaseOriginalPath, (string)$mainLeasePath);
    }
}
unset($GLOBALS['qdDev18CleanupActive']);
if ($driverFailed) exit(2);
