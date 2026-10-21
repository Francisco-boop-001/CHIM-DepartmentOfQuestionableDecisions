<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/dispatcher.php';

function journal_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function journal_uuid(): string
{
    return sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
}

function journal_request(string $operation, ?string $serverContext, string $viewToken, array $payload = []): stdClass
{
    return json_decode(json_encode([
        'version' => 1,
        'requestId' => journal_uuid(),
        'operation' => $operation,
        'context' => (object)[
            'viewToken' => $viewToken,
            'nativeGeneration' => '1',
            'serverContext' => $serverContext,
        ],
        'payload' => (object)$payload,
    ], JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
}

function journal_error(array $response, string $code): void
{
    journal_check(($response['ok'] ?? null) === false && ($response['error']['code'] ?? null) === $code, 'expected ' . $code . ' journal response');
}

function journal_data(array $response): object
{
    $wire = json_decode(json_encode($response, JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
    journal_check(is_object($wire) && ($wire->ok ?? null) === true && is_object($wire->data ?? null), 'expected successful journal response');
    return $wire->data;
}

function journal_cleanup(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

if (($argv[1] ?? null) === '--directory-fsync-guard') {
    $missingDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-journal-missing-' . bin2hex(random_bytes(8));
    $missingPathRejected = false;
    try {
        qd_journal_fsync_directory($missingDirectory);
    } catch (QDServerFault $error) {
        $missingPathRejected = $error->wireCode === 'unavailable';
    }
    journal_check($missingPathRejected, 'the journal directory-fsync guard must refuse a missing directory');
    $capability = 'not-probed';
    if (DIRECTORY_SEPARATOR !== '\\') {
        qd_journal_fsync_directory(sys_get_temp_dir());
        $capability = 'POSIX-directory-sync';
    }
    echo "PASS QD01 directory-fsync guard refusal ($capability)\n";
    exit(0);
}

if (($argv[1] ?? null) === '--reopen') {
    $root = $argv[2] ?? '';
    $metaPath = $root . DIRECTORY_SEPARATOR . 'reopen.json';
    $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true, 8, JSON_THROW_ON_ERROR) : null;
    journal_check(is_array($meta), 'reopen fixture metadata must exist');
    $settings = [
        'pairingPath' => $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'pairing.json',
        'statePath' => $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'state.json',
        'webRoot' => $root . DIRECTORY_SEPARATOR . 'web',
    ];
    [$pairId, $scopeHash, $operation, $firstId, $firstPayloadHash, $firstSemanticHash] = $meta['first'];
    try {
        qd_journal_begin($settings, $pairId, $scopeHash, $operation, $firstId, $firstPayloadHash, $firstSemanticHash);
        journal_check(false, 'compacted same-ID tombstone must remain non-replayable after process reopen');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'conflict', 'reopened compacted tombstone must fail closed with conflict');
    }
    [$pairId, $scopeHash, $operation, $lastId, $lastPayloadHash, $lastSemanticHash] = $meta['last'];
    $replay = qd_journal_begin($settings, $pairId, $scopeHash, $operation, $lastId, $lastPayloadHash, $lastSemanticHash);
    journal_check(($replay['action'] ?? null) === 'replay' && ($replay['data']->index ?? null) === 4099, 'recent completed operation must replay its result after process reopen');
    $nextId = journal_uuid();
    $next = qd_journal_begin($settings, $pairId, $scopeHash, $operation, $nextId, $lastPayloadHash, $lastSemanticHash);
    journal_check(($next['action'] ?? null) === 'dispatch', 'reopened journal must accept new work after completed semantic operation');
    qd_journal_complete($settings, $next['key'], json_encode((object)['operationId' => $nextId, 'index' => 4100], JSON_THROW_ON_ERROR));
    echo "PASS QD01 persistent replay, tombstone, and post-reopen dispatch\n";
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-journal-' . bin2hex(random_bytes(8));
$webRoot = $root . DIRECTORY_SEPARATOR . 'web';
$private = $root . DIRECTORY_SEPARATOR . 'private';
mkdir($webRoot, 0700, true);
mkdir($private, 0700, true);
$pairingPath = $private . DIRECTORY_SEPARATOR . 'pairing.json';
$statePath = $private . DIRECTORY_SEPARATOR . 'state.json';
$pair = qd_pair_issue($pairingPath, $webRoot, ['pcv']);
$trace = (object)['scope' => 'qd-journal-test-scope', 'armCalls' => 0];
$dependencies = [
    'currentScope' => static fn(string $pairId): string => $trace->scope,
    'readiness' => static fn(): array => ['pcv' => true],
    'operations' => [],
];
$settings = ['pairingPath' => $pairingPath, 'statePath' => $statePath, 'webRoot' => $webRoot];
$send = static fn(stdClass $request, array $deps): array => qd_server_dispatch($request, 'Bearer ' . $pair['token'], $settings, $deps);
$viewToken = journal_uuid();

try {
    $gapSettings = $settings;
    $gapSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'reservation-gap.json';
    $gapPairId = $pair['pairId'];
    $gapScopeHash = hash('sha256', $trace->scope);
    $gapOperation = 'pcv.arm';
    $gapOperationId = journal_uuid();
    $gapPayloadHash = hash('sha256', 'reservation-gap-payload');
    $gapSemanticHash = hash('sha256', 'reservation-gap-semantic');

    $legacyGapSettings = $settings;
    $legacyGapSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'legacy-reservation-gap.json';
    $legacyGapDirectory = qd_journal_directory($legacyGapSettings);
    $legacyGapSemanticHash = hash('sha256', 'legacy-reservation-gap-semantic');
    $legacyGapSemanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $legacyGapSemanticHash);
    $legacyGapOperationId = journal_uuid();
    $legacyGapOperationKey = hash('sha256', implode("\0", [$gapPairId, $gapScopeHash, $gapOperation, $legacyGapOperationId]));
    $legacyGapPointerPath = $legacyGapDirectory . DIRECTORY_SEPARATOR . $legacyGapSemanticKey . '.pending.json';
    $legacyGapPointerBytes = json_encode(['version' => 1, 'operationKey' => $legacyGapOperationKey], JSON_THROW_ON_ERROR);
    file_put_contents($legacyGapPointerPath, $legacyGapPointerBytes);
    try {
        qd_journal_begin($legacyGapSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $legacyGapSemanticHash);
        journal_check(false, 'a missing-target legacy v1 pointer must remain poisoned rather than be guessed safe');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'conflict', 'the old v1 orphan must reproduce fail-closed semantic poisoning');
    }
    journal_check(file_get_contents($legacyGapPointerPath) === $legacyGapPointerBytes,
        'a legacy v1 orphan must remain unchanged for operator reconciliation');

    $gapDirectory = qd_journal_directory($gapSettings);
    $gapKey = hash('sha256', implode("\0", [$gapPairId, $gapScopeHash, $gapOperation, $gapOperationId]));
    $gapSemanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $gapSemanticHash);
    $gapPointerPath = $gapDirectory . DIRECTORY_SEPARATOR . $gapSemanticKey . '.pending.json';
    journal_check(file_put_contents($gapPointerPath, json_encode(['version' => 2, 'operationKey' => $gapKey, 'commitEntered' => false], JSON_THROW_ON_ERROR)) > 0,
        'the reservation-gap fixture must persist its v2 false marker');
    $gapPointer = json_decode((string)file_get_contents($gapPointerPath), true, 8, JSON_THROW_ON_ERROR);
    journal_check(($gapPointer['version'] ?? null) === 2 && ($gapPointer['commitEntered'] ?? null) === false
        && ($gapPointer['operationKey'] ?? null) === $gapKey,
        'the reservation-gap fixture must represent a durable v2 no-write marker');
    $gapRetry = qd_journal_begin($gapSettings, $gapPairId, $gapScopeHash, $gapOperation, $gapOperationId, $gapPayloadHash, $gapSemanticHash);
    journal_check(($gapRetry['action'] ?? null) === 'dispatch', 'a missing-record v2 false marker must allow exact-ID reservation recovery');
    try {
        qd_journal_begin($gapSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $gapSemanticHash);
        journal_check(false, 'a present pending record must never be auto-reclaimed under a new operation ID');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'conflict', 'a present pending record must keep the semantic slot unresolved');
    }
    qd_journal_mark_commit($gapSettings, $gapRetry['key']);
    $enteredPointer = json_decode((string)file_get_contents($gapPointerPath), true, 8, JSON_THROW_ON_ERROR);
    journal_check(($enteredPointer['version'] ?? null) === 2 && ($enteredPointer['commitEntered'] ?? null) === true,
        'commit entry must be durable in the semantic pointer before the record marker');
    try {
        qd_journal_begin($gapSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $gapSemanticHash);
        journal_check(false, 'a v2 true marker must block another operation ID');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'conflict', 'a v2 true marker must remain non-replayable');
    }
    qd_journal_complete($gapSettings, $gapRetry['key'], json_encode((object)['operationId' => $gapOperationId], JSON_THROW_ON_ERROR));
    $postCompleteRetry = qd_journal_begin($gapSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $gapSemanticHash);
    journal_check(($postCompleteRetry['action'] ?? null) === 'dispatch', 'a terminal completion must release one outstanding slot');
    qd_journal_abandon_no_write($gapSettings, $postCompleteRetry['key']);

    $completionGapSettings = $settings;
    $completionGapSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'completion-marker-gap.json';
    $completionGapId = journal_uuid();
    $completionGap = qd_journal_begin($completionGapSettings, $gapPairId, $gapScopeHash, $gapOperation, $completionGapId, $gapPayloadHash, hash('sha256', 'completion-marker-gap'));
    qd_journal_complete($completionGapSettings, $completionGap['key'], json_encode((object)['operationId' => $completionGapId], JSON_THROW_ON_ERROR));
    $completionGapRecord = json_decode((string)file_get_contents($completionGapSettings['statePath'] . '.journal' . DIRECTORY_SEPARATOR . $completionGap['key'] . '.json'), true, 8, JSON_THROW_ON_ERROR);
    journal_check(($completionGapRecord['state'] ?? null) === 'completed' && ($completionGapRecord['commitEntered'] ?? null) === true,
        'completion must durably repair a false reservation marker before recording its terminal result');

    $newIdGapSettings = $settings;
    $newIdGapSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'reservation-gap-new-id.json';
    $newIdGapDirectory = qd_journal_directory($newIdGapSettings);
    $newIdGapSemanticHash = hash('sha256', 'reservation-gap-new-id-semantic');
    $newIdGapSemanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $newIdGapSemanticHash);
    $oldOperationId = journal_uuid();
    $oldOperationKey = hash('sha256', implode("\0", [$gapPairId, $gapScopeHash, $gapOperation, $oldOperationId]));
    $newIdGapPointerPath = $newIdGapDirectory . DIRECTORY_SEPARATOR . $newIdGapSemanticKey . '.pending.json';
    file_put_contents($newIdGapPointerPath, json_encode(['version' => 2, 'operationKey' => $oldOperationKey, 'commitEntered' => false], JSON_THROW_ON_ERROR));
    $newIdGapRetry = qd_journal_begin($newIdGapSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $newIdGapSemanticHash);
    journal_check(($newIdGapRetry['action'] ?? null) === 'dispatch', 'a missing-record v2 false marker must also allow a freshly reviewed new-ID reservation');
    qd_journal_abandon_no_write($newIdGapSettings, $newIdGapRetry['key']);

    $enteredGapSettings = $settings;
    $enteredGapSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'entered-marker-gap.json';
    $enteredGapSemanticHash = hash('sha256', 'entered-marker-gap-semantic');
    $enteredGapOperationId = journal_uuid();
    $enteredGapPayloadHash = hash('sha256', 'entered-marker-gap-payload');
    $enteredGap = qd_journal_begin($enteredGapSettings, $gapPairId, $gapScopeHash, $gapOperation, $enteredGapOperationId, $enteredGapPayloadHash, $enteredGapSemanticHash);
    $enteredGapDirectory = $enteredGapSettings['statePath'] . '.journal';
    $enteredGapSemanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $enteredGapSemanticHash);
    $enteredGapPointerPath = $enteredGapDirectory . DIRECTORY_SEPARATOR . $enteredGapSemanticKey . '.pending.json';
    file_put_contents($enteredGapPointerPath, json_encode(['version' => 2, 'operationKey' => $enteredGap['key'], 'commitEntered' => true], JSON_THROW_ON_ERROR));
    qd_journal_mark_commit($enteredGapSettings, $enteredGap['key']);
    $enteredGapRecord = json_decode((string)file_get_contents($enteredGapDirectory . DIRECTORY_SEPARATOR . $enteredGap['key'] . '.json'), true, 8, JSON_THROW_ON_ERROR);
    journal_check(($enteredGapRecord['commitEntered'] ?? null) === true,
        'mark_commit must finish a crash-gap where the durable pointer is true before the record is updated');
    qd_journal_abandon_no_write($enteredGapSettings, $enteredGap['key']);

    $mismatchSettings = $settings;
    $mismatchSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'pointer-mismatch.json';
    $mismatchSemanticHash = hash('sha256', 'pointer-mismatch-a');
    $mismatchOtherSemanticHash = hash('sha256', 'pointer-mismatch-b');
    $mismatchOther = qd_journal_begin($mismatchSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $mismatchOtherSemanticHash);
    $mismatchSemanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $mismatchSemanticHash);
    $mismatchDirectory = $mismatchSettings['statePath'] . '.journal';
    file_put_contents($mismatchDirectory . DIRECTORY_SEPARATOR . $mismatchSemanticKey . '.pending.json',
        json_encode(['version' => 2, 'operationKey' => $mismatchOther['key'], 'commitEntered' => false], JSON_THROW_ON_ERROR));
    try {
        qd_journal_begin($mismatchSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), $gapPayloadHash, $mismatchSemanticHash);
        journal_check(false, 'a semantic pointer to a record for another semantic key must fail closed');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'unavailable', 'semantic-key inconsistency must be unavailable');
    }

    $capacitySettings = $settings;
    $capacitySettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'capacity-state.json';
    $capacityKeys = [];
    for ($index = 0; $index < 64; $index++) {
        $capacityOperationId = journal_uuid();
        $capacityPayloadHash = hash('sha256', 'capacity-payload-' . $index);
        $capacitySemanticHash = hash('sha256', 'capacity-semantic-' . $index);
        $capacityEntry = qd_journal_begin($capacitySettings, $gapPairId, $gapScopeHash, $gapOperation, $capacityOperationId, $capacityPayloadHash, $capacitySemanticHash);
        journal_check(($capacityEntry['action'] ?? null) === 'dispatch', 'each of the 64 outstanding semantic reservations must be admitted');
        $capacityKeys[] = [$capacityEntry['key'], $capacityOperationId];
    }
    $capacityFull = false;
    try {
        qd_journal_begin($capacitySettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), hash('sha256', 'capacity-overflow'), hash('sha256', 'capacity-overflow-semantic'));
    } catch (QDServerFault $error) {
        $capacityFull = $error->wireCode === 'unavailable';
    }
    journal_check($capacityFull, 'the journal must refuse admission above 64 unresolved semantic reservations');
    qd_journal_mark_commit($capacitySettings, $capacityKeys[0][0]);
    qd_journal_complete($capacitySettings, $capacityKeys[0][0], json_encode((object)['operationId' => $capacityKeys[0][1]], JSON_THROW_ON_ERROR));
    $afterCapacityCompletion = qd_journal_begin($capacitySettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), hash('sha256', 'capacity-after-completion'), hash('sha256', 'capacity-after-completion-semantic'));
    journal_check(($afterCapacityCompletion['action'] ?? null) === 'dispatch', 'a terminal completion must free journal admission capacity');
    qd_journal_abandon_no_write($capacitySettings, $afterCapacityCompletion['key']);

    $safeOrphanSettings = $settings;
    $safeOrphanSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'safe-orphan-capacity.json';
    $safeOrphanDirectory = qd_journal_directory($safeOrphanSettings);
    $safeOrphanPaths = [];
    for ($index = 0; $index < QD_JOURNAL_MAX_OUTSTANDING; $index++) {
        $semanticHash = hash('sha256', 'safe-orphan-semantic-' . $index);
        $semanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $semanticHash);
        $operationKey = hash('sha256', implode("\0", [$gapPairId, $gapScopeHash, $gapOperation, journal_uuid()]));
        $path = $safeOrphanDirectory . DIRECTORY_SEPARATOR . $semanticKey . '.pending.json';
        file_put_contents($path, json_encode(['version' => 2, 'operationKey' => $operationKey, 'commitEntered' => false], JSON_THROW_ON_ERROR));
        $safeOrphanPaths[] = $path;
    }
    $afterSafeOrphans = qd_journal_begin($safeOrphanSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), hash('sha256', 'safe-orphan-overflow-payload'), hash('sha256', 'safe-orphan-overflow-semantic'));
    journal_check(($afterSafeOrphans['action'] ?? null) === 'dispatch' && !array_filter($safeOrphanPaths, 'file_exists'),
        'admission must reclaim each proven no-write v2 orphan instead of letting stale reservations block new semantics');
    qd_journal_abandon_no_write($safeOrphanSettings, $afterSafeOrphans['key']);

    $unknownOrphanSettings = $settings;
    $unknownOrphanSettings['statePath'] = $private . DIRECTORY_SEPARATOR . 'unknown-orphan-capacity.json';
    $unknownOrphanDirectory = qd_journal_directory($unknownOrphanSettings);
    $unknownOrphanPaths = [];
    for ($index = 0; $index < QD_JOURNAL_MAX_OUTSTANDING; $index++) {
        $semanticHash = hash('sha256', 'unknown-orphan-semantic-' . $index);
        $semanticKey = qd_journal_semantic_key($gapPairId, $gapScopeHash, $gapOperation, $semanticHash);
        $operationKey = hash('sha256', implode("\0", [$gapPairId, $gapScopeHash, $gapOperation, journal_uuid()]));
        $path = $unknownOrphanDirectory . DIRECTORY_SEPARATOR . $semanticKey . '.pending.json';
        file_put_contents($path, json_encode(['version' => 2, 'operationKey' => $operationKey, 'commitEntered' => true], JSON_THROW_ON_ERROR));
        $unknownOrphanPaths[] = $path;
    }
    $unknownOrphanBlocked = false;
    try {
        qd_journal_begin($unknownOrphanSettings, $gapPairId, $gapScopeHash, $gapOperation, journal_uuid(), hash('sha256', 'unknown-orphan-overflow-payload'), hash('sha256', 'unknown-orphan-overflow-semantic'));
    } catch (QDServerFault $error) {
        $unknownOrphanBlocked = $error->wireCode === 'unavailable';
    }
    journal_check($unknownOrphanBlocked && count(array_filter($unknownOrphanPaths, 'file_exists')) === QD_JOURNAL_MAX_OUTSTANDING,
        '64 unknown commit-entered orphans must block admission and remain untouched for reconciliation');

    if (($argv[1] ?? null) === '--boundary-only') {
        echo "PASS QD01 semantic-pointer write boundary and reservation recovery\n";
        exit(0);
    }

    $legacyStatePath = $private . DIRECTORY_SEPARATOR . 'legacy-state.json';
    $legacySettings = $settings;
    $legacySettings['statePath'] = $legacyStatePath;
    $legacyBytes = json_encode(['version' => 1, 'contexts' => [], 'operations' => ['legacy-entry' => ['commit' => 'unknown']]], JSON_THROW_ON_ERROR);
    file_put_contents($legacyStatePath, $legacyBytes);
    try {
        qd_journal_begin($legacySettings, $pair['pairId'], hash('sha256', $trace->scope), 'pcv.arm', journal_uuid(), hash('sha256', 'legacy-payload'), hash('sha256', 'legacy-semantic'));
        journal_check(false, 'non-empty legacy mutation history must fail closed');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'unavailable', 'non-empty legacy state must refuse unsafe journal migration');
    }
    journal_check(file_get_contents($legacyStatePath) === $legacyBytes
        && !is_dir($legacyStatePath . '.journal'),
        'legacy state must remain byte-identical and must not create a replacement journal on refusal');

    $boot = journal_data($send(journal_request('bootstrap', null, $viewToken), $dependencies));
    $payload = [
        'operationId' => journal_uuid(),
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

    $refusalDeps = $dependencies;
    $refusalDeps['operations']['pcv.arm'] = static function (): never {
        throw new QDServerFault('conflict');
    };
    journal_error($send(journal_request('pcv.arm', $boot->serverContext, $viewToken, $payload), $refusalDeps), 'conflict');

    $dispatchDeps = $dependencies;
    $dispatchDeps['operations']['pcv.arm'] = static function (stdClass $input, array $context) use ($trace): array {
        ($context['beginCommit'])();
        $trace->armCalls++;
        return ['operationId' => $input->operationId, 'state' => 'staged'];
    };
    $payload['operationId'] = journal_uuid();
    $first = journal_data($send(journal_request('pcv.arm', $boot->serverContext, $viewToken, $payload), $dispatchDeps));
    journal_check(($first->state ?? null) === 'staged' && $trace->armCalls === 1, 'typed precommit refusal must release the semantic guard for a new operation ID');

    $ambiguousDeps = $dependencies;
    $ambiguousDeps['operations']['pcv.arm'] = static function (stdClass $input, array $context) use ($trace): never {
        ($context['beginCommit'])();
        $trace->armCalls++;
        throw new QDServerFault('conflict');
    };
    $ambiguousPayload = $payload;
    $ambiguousPayload['operationId'] = journal_uuid();
    $ambiguousRequest = journal_request('pcv.arm', $boot->serverContext, $viewToken, $ambiguousPayload);
    journal_error($send($ambiguousRequest, $ambiguousDeps), 'conflict');
    journal_error($send($ambiguousRequest, $dispatchDeps), 'conflict');
    $newId = $ambiguousPayload;
    $newId['operationId'] = journal_uuid();
    journal_error($send(journal_request('pcv.arm', $boot->serverContext, $viewToken, $newId), $dispatchDeps), 'conflict');
    journal_check($trace->armCalls === 2, 'a failure after durable commit entry must remain unresolved and never dispatch again');

    $pairId = $pair['pairId'];
    $scopeHash = hash('sha256', $trace->scope);
    $operation = 'pcv.arm';
    $firstKey = null;
    $last = null;
    for ($index = 0; $index < 4100; $index++) {
        $operationId = journal_uuid();
        $retentionPayload = $payload;
        $retentionPayload['operationId'] = $operationId;
        $retentionPayload['expectedActiveConfigId'] = journal_uuid();
        $request = journal_request($operation, $boot->serverContext, $viewToken, $retentionPayload);
        $payloadHash = hash('sha256', qd_canonical_json($request->payload));
        $semanticPayload = clone $request->payload;
        unset($semanticPayload->operationId);
        $semanticHash = hash('sha256', qd_canonical_json($semanticPayload));
        $iterationDeps = $dispatchDeps;
        $iterationDeps['operations']['pcv.arm'] = static function (stdClass $input, array $context) use ($trace, $index): array {
            ($context['beginCommit'])();
            $trace->armCalls++;
            return ['operationId' => $input->operationId, 'index' => $index, 'state' => 'staged'];
        };
        $response = journal_data($send($request, $iterationDeps));
        journal_check(($response->index ?? null) === $index && ($response->state ?? null) === 'staged', 'journal must accept successful API mutation beyond the previous lifetime caps');
        if ($index === 0) {
            $firstKey = [$pairId, $scopeHash, $operation, $operationId, $payloadHash, $semanticHash];
        }
        $last = [$pairId, $scopeHash, $operation, $operationId, $payloadHash, $semanticHash];
    }
    journal_check(is_array($firstKey) && is_array($last), 'retention fixture must cover the first and last operation');
    [$firstPair, $firstScope, $firstOperation, $firstId, $firstPayloadHash, $firstSemanticHash] = $firstKey;
    try {
        qd_journal_begin($settings, $firstPair, $firstScope, $firstOperation, $firstId, $firstPayloadHash, $firstSemanticHash);
        journal_check(false, 'compacted same-ID tombstone must not replay or redispatch');
    } catch (QDServerFault $error) {
        journal_check($error->wireCode === 'conflict', 'compacted same-ID tombstone must fail closed with conflict');
    }
    [$lastPair, $lastScope, $lastOperation, $lastId, $lastPayloadHash, $lastSemanticHash] = $last;
    $replay = qd_journal_begin($settings, $lastPair, $lastScope, $lastOperation, $lastId, $lastPayloadHash, $lastSemanticHash);
    journal_check(($replay['action'] ?? null) === 'replay' && ($replay['data']->index ?? null) === 4099, 'recent completed operation must replay the saved result');

    file_put_contents($root . DIRECTORY_SEPARATOR . 'reopen.json', json_encode(['first' => $firstKey, 'last' => $last], JSON_THROW_ON_ERROR));
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--reopen', $root], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    journal_check(is_resource($process), 'journal process-reopen child must start');
    fclose($pipes[0]);
    $reopenOutput = stream_get_contents($pipes[1]);
    $reopenErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $reopenExit = proc_close($process);
    journal_check($reopenExit === 0, 'journal process-reopen child must pass: ' . trim((string)$reopenOutput . ' ' . (string)$reopenErrors));

    $retry = qd_journal_begin($settings, $lastPair, $lastScope, $lastOperation, journal_uuid(), $lastPayloadHash, $lastSemanticHash);
    journal_check(($retry['action'] ?? null) === 'dispatch', 'completed semantic payload may be repeated under a new operation ID');
    qd_journal_abandon_no_write($settings, $retry['key']);

    $journalDirectory = $statePath . '.journal';
    $recentFiles = glob($journalDirectory . DIRECTORY_SEPARATOR . '*.recent.json') ?: [];
    journal_check(count($recentFiles) >= 1, 'per-principal detailed-result index must exist');
    $retainedKeys = json_decode((string)file_get_contents($recentFiles[0]), true, 8, JSON_THROW_ON_ERROR)['keys'] ?? null;
    journal_check(is_array($retainedKeys) && count($retainedKeys) === 128, 'detailed replay retention must stay within 128 operations per principal');
    $sharedState = qd_server_state_transaction($settings, static fn(array &$state): array => $state);
    journal_check(($sharedState['operations'] ?? null) === [], 'the shared context state must not accumulate operation history');

    echo "PASS QD01 known-no-write, uncertain commit, 4100-operation retention across process reopen, and tombstone behavior\n";
} finally {
    journal_cleanup($root);
}
