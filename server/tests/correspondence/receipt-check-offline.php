<?php
declare(strict_types=1);

$departmentRoot = dirname(__DIR__, 3);
$emitDir = null;
$options = getopt('', ['owner-root:', 'support-root:', 'emit-dir:']);
$ownerRoot = $options['owner-root'] ?? null;
$supportRoot = $options['support-root'] ?? null;
if (is_string($options['emit-dir'] ?? null) && $options['emit-dir'] !== '') $emitDir = $options['emit-dir'];
if (!is_string($ownerRoot) || $ownerRoot === '' || !is_string($supportRoot) || $supportRoot === '') {
    throw new RuntimeException('Pass --owner-root and --support-root for the exact synthetic owner and helper sources.');
}
if (function_exists('pg_connect') || function_exists('curl_init')) throw new RuntimeException('Run PHP -n with database/network extensions absent.');
if (!is_dir($ownerRoot) || !is_file($ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php')) {
    throw new RuntimeException('The exact staged dev33 owner source is unavailable.');
}

require_once $departmentRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php';
require_once $ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
require_once $supportRoot . DIRECTORY_SEPARATOR . 'correspondence_memory_store.php';
require_once $supportRoot . DIRECTORY_SEPARATOR . 'correspondence_core_function.php';

$adapterPath = $departmentRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
$adapterBytes = file_get_contents($adapterPath);
if (!is_string($adapterBytes)) throw new RuntimeException('Could not read the Department Correspondence adapter.');
$constantStart = strpos($adapterBytes, 'const QD_CORRESPONDENCE_MAILBOX_SCAN_MAX');
$constantEnd = is_int($constantStart) ? strpos($adapterBytes, "\nfunction ", $constantStart) : false;
if (!is_int($constantStart) || !is_int($constantEnd)) throw new RuntimeException('Could not extract the fixed Correspondence source pins.');
eval(substr($adapterBytes, $constantStart, $constantEnd - $constantStart));
$adapterHash = hash('sha256', $adapterBytes);

$GLOBALS['receiptCheckOwnerRoot'] = realpath($ownerRoot);
$GLOBALS['receiptCheckCoreRoot'] = realpath(dirname($ownerRoot, 2));
$GLOBALS['receiptCheckFlags'] = [
    'contextStale' => false,
    'restoreStale' => false,
    'sourceChangedAtFence' => false,
    'sourceChanged' => false,
    'scopeChanged' => false,
];
$GLOBALS['receiptCheckRestoreToken'] = 'synthetic-restore-token';
$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = $GLOBALS['receiptCheckRestoreToken'];
$GLOBALS['receiptCheckActive'] = [
    'profileId' => 'unprofiled',
    'scopeKey' => str_repeat('a', 64),
];
$GLOBALS['receiptCheckLease'] = fopen('php://memory', 'r');
if (!is_resource($GLOBALS['receiptCheckLease'])) throw new RuntimeException('Could not create a synthetic owner lease handle.');

function qd_correspondence_source_root(): string
{
    return (string)$GLOBALS['receiptCheckOwnerRoot'];
}

function qd_runtime_core_root(): string
{
    return (string)$GLOBALS['receiptCheckCoreRoot'];
}

function qd_correspondence_current_source_identity(string $root): ?array
{
    if (($GLOBALS['receiptCheckFlags']['sourceChanged'] ?? false) === true) return null;
    $root = realpath($root);
    if (!is_string($root) || !qd_correspondence_manifest_ready($root)) return null;
    $bytes = file_get_contents($root . DIRECTORY_SEPARATOR . 'manifest.json');
    $manifest = is_string($bytes) ? json_decode($bytes, true, 8) : null;
    if (!is_string($bytes) || !is_array($manifest) || !is_string($manifest['version'] ?? null)) return null;
    return ['root' => $root, 'version' => $manifest['version'], 'manifestHash' => hash('sha256', $bytes)];
}

function qd_correspondence_load_sources(): string
{
    $root = qd_correspondence_source_root();
    $identity = qd_correspondence_current_source_identity($root);
    if (!is_array($identity)) throw new QDServerFault('unavailable');
    qd_correspondence_loaded_source_identity($identity);
    return $root;
}

function qd_correspondence_dev18_fenced_snapshot(string $scopeKey, array $authority, callable $callback): mixed
{
    if (($GLOBALS['receiptCheckFlags']['restoreStale'] ?? false) === true) throw new QDServerFault('stale_context');
    $active = qd_runtime_active_playthrough('synthetic-core-connection');
    $result = $callback('synthetic-core-connection', $active);
    if (($GLOBALS['receiptCheckFlags']['sourceChangedAtFence'] ?? false) === true) {
        $GLOBALS['receiptCheckFlags']['sourceChanged'] = true;
    }
    return $result;
}

function qd_runtime_active_playthrough(mixed $connection): array
{
    if (!in_array($connection, ['synthetic-core-connection', 'synthetic-owner-connection'], true)) {
        throw new RuntimeException('Unexpected offline connection marker.');
    }
    $active = $GLOBALS['receiptCheckActive'];
    if (($GLOBALS['receiptCheckFlags']['scopeChanged'] ?? false) === true) $active['scopeKey'] = str_repeat('b', 64);
    return $active;
}

function qd_runtime_with_correspondence_owner_lease(string $ownerRoot, string $expectedGeneration, mixed $lease, callable $callback): mixed
{
    if ($ownerRoot !== $GLOBALS['receiptCheckOwnerRoot'] || $expectedGeneration !== 'synthetic-generation'
        || $lease !== $GLOBALS['receiptCheckLease'] || !is_resource($lease)) throw new QDServerFault('unavailable');
    return $callback();
}

function qd_runtime_with_owned_connection(callable $connect, callable $close, callable $callback): mixed
{
    $connection = $connect();
    try { return $callback($connection); }
    finally { $close($connection); }
}

function correspondenceNativeConnection(): string
{
    return 'synthetic-owner-connection';
}

function correspondenceCaptureRequestIngress(object $store): void
{
    // The offline store exposes the already-frozen synthetic restore token.
}

function qd_correspondence_autonomy_child(object $store, array $doc): ?bool
{
    return false;
}

if (!function_exists('qd_correspondence_pg_rows')) {
    function qd_correspondence_pg_rows(mixed $connection, string $sql, array $parameters = []): array
    {
        if ($connection !== 'synthetic-core-connection') throw new RuntimeException('Unexpected synthetic read connection.');
        $fixture = $GLOBALS['receiptCheckReadFixture'] ?? null;
        if (str_contains($sql, 'SELECT ts,gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1')) {
            return [['ts' => (string)($fixture['nativeTs'] ?? '100'), 'gamets' => '100']];
        }
        if (str_contains($sql, 'FROM public.core_npc_master')) {
            if (str_contains($sql, 'SELECT refid FROM')) return [['refid' => '0001A677']];
            return [['id' => 900002, 'npc_name' => 'Synthetic recipient', 'refid' => '0001A677', 'profile_id' => '']];
        }
        if (str_contains($sql, 'FROM public.core_player')) return [['value' => 'Dragonborn']];
        if (str_contains($sql, 'FROM public.game_plugins')) return [['plugin_name' => 'Skyrim.esm', 'formid_prefix' => '00']];
        if (str_contains($sql, "to_regclass('plugins.correspondence_companion_decision')")) return [['decision_table' => null]];
        if (str_contains($sql, 'FROM plugins.correspondence_documents d LEFT JOIN')) {
            if (!is_array($fixture) || !($fixture['memory'] ?? null) instanceof CorrespondenceMemoryStore) return [];
            $doc = $fixture['memory']->docs[$fixture['documentId']] ?? null;
            $operation = $fixture['memory']->operations[$fixture['operationId']] ?? null;
            if (!is_array($doc) || !is_array($operation)) return [];
            return [[
                'document_id' => $doc['document_id'],
                'document_payload' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'operation_payload' => json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => '2026-11-10 12:34:56+00',
            ]];
        }
        if (str_contains($sql, 'FROM public.eventlog WHERE rowid=ANY')) {
            return is_array($fixture) && ($fixture['memory'] ?? null) instanceof CorrespondenceMemoryStore
                ? array_values($fixture['memory']->events)
                : [];
        }
        return [];
    }
}

final class CorrespondencePgStore implements CorrespondenceStore
{
    private CorrespondenceMemoryStore $memory;
    private Closure $executorResolver;
    private Closure $notePreparer;

    public function __construct(mixed $connection, callable $executorResolver, callable $notePreparer, ?string $restorePath = null)
    {
        if ($connection !== 'synthetic-owner-connection') throw new RuntimeException('Unexpected offline owner connection.');
        $this->memory = $GLOBALS['receiptCheckMemory'];
        $this->executorResolver = Closure::fromCallable($executorResolver);
        $this->notePreparer = Closure::fromCallable($notePreparer);
    }

    public function ensureSchema(): void {}
    public function requestRestoreToken(): string { return (string)$GLOBALS['receiptCheckRestoreToken']; }
    public function assertRestoreToken(string $expected): void
    {
        if (($GLOBALS['receiptCheckFlags']['restoreStale'] ?? false) === true
            || !hash_equals((string)$GLOBALS['receiptCheckRestoreToken'], $expected)) throw new QDServerFault('stale_context');
    }
    public function transaction(callable $work): mixed { return $this->memory->transaction($work); }
    public function readTransaction(callable $work): mixed { return $this->memory->readTransaction($work); }
    public function scope(): array { return $this->memory->scope(); }
    public function actors(): array { return $this->memory->actors(); }
    public function transactionMemo(string $key, callable $build): mixed { return $this->memory->transactionMemo($key, $build); }
    public function player(): string { return $this->memory->player(); }
    public function lockActorRows(array $npcIds, bool $includePlayer = false): array { return $this->memory->lockActorRows($npcIds, $includePlayer); }
    public function findRequest(string $scope, string $request): ?array { return $this->memory->findRequest($scope, $request); }
    public function loadDocument(string $id): ?array { return $this->memory->loadDocument($id); }
    public function saveDocument(array $document): void { $this->memory->saveDocument($document); }
    public function documents(string $scope, ?int $recipientId = null): array { return $this->memory->documents($scope, $recipientId); }
    public function loadOperation(string $id): ?array { return $this->memory->loadOperation($id); }
    public function saveOperation(array $operation): void { $this->memory->saveOperation($operation); }
    public function appendEvent(string $type, string $data, string $people, string $sess): array { return $this->memory->appendEvent($type, $data, $people, $sess); }
    public function eventMatches(array $event): bool { return $this->memory->eventMatches($event); }
    public function managedExecutor(): ?string { return ($this->executorResolver)($this); }
    public function prepareNote(string $title, string $body): void { ($this->notePreparer)($title, $body); }
    public function queueCommand(string $actor, string $action): void { $this->memory->queueCommand($actor, $action); }
    public function physicalActor(array $actor): array { return $this->memory->physicalActor($actor); }
    public function physicalItem(array $item): array { return $this->memory->physicalItem($item); }
    public function observedExecutor(): ?string { return $this->memory->managedExecutor(); }
    public function eventRow(array $anchor): ?array
    {
        $rowId = $anchor['rowid'] ?? null;
        return is_int($rowId) ? ($this->memory->events[$rowId] ?? null) : null;
    }
    public function companionDecisionRows(string $documentId): ?array { return []; }
    public function query(string $sql, array $parameters = []): array
    {
        if (str_starts_with($sql, 'SAVEPOINT ') || str_starts_with($sql, 'RELEASE SAVEPOINT ') || str_starts_with($sql, 'ROLLBACK TO SAVEPOINT ')) return [];
        if (str_contains($sql, "to_regclass('plugins.correspondence_companion_decision')")) return [['owned' => 'plugins.correspondence_companion_decision']];
        if (str_contains($sql, "to_regclass('plugins.correspondence_autonomy_decisions')")) return [['decision_table' => 'plugins.correspondence_autonomy_decisions']];
        if (str_contains($sql, 'plugins.correspondence_companion_decision WHERE')) return [];
        if (str_contains($sql, 'plugins.correspondence_autonomy_decisions WHERE')) return [];
        if (str_contains($sql, 'SELECT EXISTS(SELECT 1 FROM plugins.correspondence_operations')) {
            $documentId = $parameters[0] ?? null;
            $present = is_string($documentId) && array_filter($this->memory->operations, static fn(array $operation): bool => ($operation['document_id'] ?? null) === $documentId) !== [];
            return [['present' => $present ? 't' : 'f']];
        }
        if (str_contains($sql, 'SELECT EXISTS(SELECT 1 FROM public.eventlog')) return [['present' => 'f']];
        return [];
    }
}

function receiptCheckLoadAdapterFunction(string $name): void
{
    correspondenceLoadPinnedFunction($GLOBALS['receiptCheckAdapterPath'], $GLOBALS['receiptCheckAdapterHash'], $name);
}

$GLOBALS['receiptCheckAdapterPath'] = $adapterPath;
$GLOBALS['receiptCheckAdapterHash'] = $adapterHash;
foreach ([
    'qd_correspondence_source_files', 'qd_correspondence_manifest_ready', 'qd_correspondence_loaded_source_identity',
    'qd_correspondence_loaded_source_version', 'qd_correspondence_version_uses_restore_fence',
    'qd_correspondence_uses_restore_fence',
    'qd_correspondence_version_supports_receipt_check', 'qd_correspondence_version_supports_pending_resume',
    'qd_correspondence_version_supports_companion_scheduler', 'qd_correspondence_version_supports_operator_close',
    'qd_correspondence_version_supports_signed_unknown', 'qd_correspondence_require_fields',
    'qd_correspondence_require_access', 'qd_correspondence_assertion', 'qd_correspondence_scope_key',
    'qd_correspondence_assert_active_scope', 'qd_correspondence_dev18_authority',
    'qd_correspondence_in_current_context', 'qd_correspondence_commit_boundary_store', 'qd_correspondence_close_owned_pg_connection',
    'qd_correspondence_companion_decision_rows',
    'qd_correspondence_fetch_event', 'qd_correspondence_bridge_receipt_wire_order', 'qd_correspondence_actor_record_key', 'qd_correspondence_document_integrity',
    'qd_correspondence_validate_receipt', 'qd_correspondence_project_result', 'qd_correspondence_resume_eligible',
    'qd_correspondence_has_operation_for_document', 'qd_correspondence_has_dispatch_anchor',
    'qd_correspondence_safe_queue_reason', 'qd_correspondence_receipt_check_guard', 'qd_correspondence_receipt_check',
    'qd_correspondence_received_at', 'qd_correspondence_clip_utf8', 'qd_correspondence_mailbox_projection', 'qd_correspondence_mailbox_read',
    'qd_correspondence_detail_read', 'qd_correspondence_actor_key', 'qd_correspondence_find_actor',
    'qd_correspondence_mailbox_source_rows', 'qd_correspondence_detail_source_rows', 'qd_correspondence_prefetch_events',
    'qd_correspondence_prefetch_companion_decisions', 'qd_correspondence_source_revision',
    'qd_correspondence_cursor_offset', 'qd_correspondence_make_cursor', 'qd_correspondence_read_store',
    'qd_correspondence_read_snapshot',
] as $function) receiptCheckLoadAdapterFunction($function);

if (!qd_correspondence_manifest_ready($ownerRoot)
    || qd_correspondence_loaded_source_version() !== '0.1.0-dev.33'
    || realpath((new ReflectionClass(CorrespondenceService::class))->getFileName() ?: '') !== realpath($ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php')) {
    throw new RuntimeException('The actual staged dev33 owner source closure did not validate.');
}

function receiptCheckExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function receiptCheckUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function receiptCheckNormalizeEvents(CorrespondenceMemoryStore $store): void
{
    foreach ($store->events as &$event) $event['type'] = 'info';
    unset($event);
    foreach ($store->docs as &$doc) {
        if (is_array($doc['anchor'] ?? null)) $doc['anchor']['type'] = 'info';
        if (is_array($doc['operator_close'] ?? null)) $doc['operator_close']['type'] = 'info';
    }
    unset($doc);
    foreach ($store->operations as &$operation) {
        if (is_array($operation['dispatch_anchor'] ?? null)) $operation['dispatch_anchor']['type'] = 'info';
        if (is_array($operation['receipt_anchor'] ?? null)) $operation['receipt_anchor']['type'] = 'info';
    }
    unset($operation);
}

function receiptCheckContext(int &$assertions, int &$commits, bool &$contextLocked): array
{
    return [
        'scopes' => ['compose'],
        'scopeKey' => str_repeat('a', 64),
        'assertCurrent' => static function () use (&$assertions): void {
            $assertions++;
            if (($GLOBALS['receiptCheckFlags']['contextStale'] ?? false) === true) throw new QDServerFault('stale_context');
        },
        'beginCommit' => static function () use (&$commits): void { $commits++; },
        'withCurrentContext' => static function (callable $callback) use (&$contextLocked): mixed {
            if ($contextLocked) throw new RuntimeException('synthetic_context_lock_reentered');
            $contextLocked = true;
            try { return $callback(); }
            finally { $contextLocked = false; }
        },
        '__qdCorrespondenceDev18' => [
            'coreRoot' => $GLOBALS['receiptCheckCoreRoot'],
            'ownerRoot' => $GLOBALS['receiptCheckOwnerRoot'],
            'generation' => 'synthetic-generation',
            'lease' => $GLOBALS['receiptCheckLease'],
        ],
    ];
}

function receiptCheckRun(CorrespondenceMemoryStore $memory, string $operationId, string $attemptId, array $context): array
{
    $GLOBALS['receiptCheckMemory'] = $memory;
    $request = (object)[
        'version' => 1,
        'requestId' => '33333333-3333-4333-8333-333333333333',
        'operation' => 'correspondence.receipt.check',
        'context' => (object)['viewToken' => '44444444-4444-4444-8444-444444444444', 'nativeGeneration' => '1', 'serverContext' => 'synthetic-context'],
        'payload' => (object)['operationId' => $operationId, 'attemptId' => $attemptId],
    ];
    $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    receiptCheckExpect(qd_parse_json_request($requestJson)['error'] === null, 'the exact receipt-check request is accepted by the production protocol parser');
    $data = qd_correspondence_receipt_check($request->payload, $context);
    $response = qd_response_with_bounded_data($request, $data, false);
    return ['request' => $request, 'requestJson' => $requestJson, 'data' => $data, 'response' => $response];
}

$assertions = 0;
$commits = 0;
$contextLocked = false;
$context = receiptCheckContext($assertions, $commits, $contextLocked);
$memory = new CorrespondenceMemoryStore();
$memory->actors[900002]['npc_name'] = 'Synthetic recipient';
$memory->executor = 'The Narrator';
$service = new CorrespondenceService($memory);
$requestId = '60cc5075-f939-4849-8c1f-e41f8c62f555';
$sent = $service->composeAndDispatch([
    'request_id' => $requestId,
    'author' => 'player',
    'payer' => 'player',
    'recipient' => 'npc:900002',
    'claimed_sender' => 'player',
    'body' => 'Synthetic dispatched receipt-check fixture.',
    'attachments' => [['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 2]],
]);
receiptCheckNormalizeEvents($memory);
$documentId = $sent['document_id'];
$ownerOperationId = $sent['operation_id'];
$baselineSaved = correspondenceCanonicalJson([$memory->docs, $memory->operations, $memory->notes]);
$baselineDeliveries = count(array_filter($memory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Deliver@')));
$baselineReconciles = count(array_filter($memory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Reconcile@')));
$success = receiptCheckRun($memory, $requestId, '22222222-2222-4222-8222-222222222222', $context);
receiptCheckExpect(($success['data']['operationId'] ?? null) === $requestId && ($success['data']['queued'] ?? null) === true
    && array_key_exists('reason', $success['data']) && $success['data']['reason'] === null
    && ($success['data']['result']['state'] ?? null) === 'dispatched',
    'the pinned owner queues a receipt-only reconciliation for the same synthetic document: ' . json_encode($success['data'], JSON_UNESCAPED_SLASHES));
receiptCheckExpect(count(array_filter($memory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Reconcile@'))) === $baselineReconciles + 1
    && str_contains(end($memory->commands)['action'], 'ExtCmdCHIMCorrespondenceBridge_Reconcile@'),
    'receipt check adds exactly one owner Reconcile command');
receiptCheckExpect(count(array_filter($memory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Deliver@'))) === $baselineDeliveries,
    'receipt check never queues a second Deliver command');
receiptCheckExpect(correspondenceCanonicalJson([$memory->docs, $memory->operations, $memory->notes]) === $baselineSaved,
    'receipt check preserves the saved document, operation, attachments, notes, and null receipt');
$successJson = json_encode($success['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$deliveredMemory = clone $memory;
$deliveredMemory->clock['ts'] = '1794314096000000000';
$deliveredService = new CorrespondenceService($deliveredMemory);
$deliveredOperation = $deliveredMemory->operations[$ownerOperationId];
$deliveredService->receive([
    'protocol_version' => 1,
    'document_id' => $documentId,
    'operation_id' => $ownerOperationId,
    'receipt_id' => $deliveredOperation['receipt_id'],
    'playthrough_id' => $deliveredOperation['playthrough_id'],
    'scope_token' => $deliveredOperation['scope_token'],
    'nonce' => $deliveredOperation['nonce'],
    'status' => 'delivered',
    'reason' => 'delivered',
    'note_form_id' => 1,
    'attachments' => [[
        'form_id' => 15,
        'quantity' => 2,
        'before_source' => 10,
        'after_source' => 8,
        'before_recipient' => 0,
        'after_recipient' => 2,
    ]],
]);
receiptCheckNormalizeEvents($deliveredMemory);
$deliveredOperation = $deliveredMemory->operations[$ownerOperationId];
$receiptRowId = $deliveredOperation['receipt_anchor']['rowid'];
$deliveredMemory->events[$receiptRowId]['localts'] = '1794314096';
$deliveredMemory->operations[$ownerOperationId]['receipt_anchor']['localts'] = '1794314096';
$jsonbReceipt = $deliveredMemory->operations[$ownerOperationId]['receipt'];
ksort($jsonbReceipt, SORT_STRING);
foreach ($jsonbReceipt['attachments'] as &$jsonbAttachment) ksort($jsonbAttachment, SORT_STRING);
unset($jsonbAttachment);
$deliveredMemory->operations[$ownerOperationId]['receipt'] = $jsonbReceipt;
$GLOBALS['receiptCheckMemory'] = $deliveredMemory;
$GLOBALS['receiptCheckReadFixture'] = [
    'memory' => $deliveredMemory,
    'documentId' => $documentId,
    'operationId' => $ownerOperationId,
    'nativeTs' => '1794314096000000000',
];
$deliveredProjectionStore = new CorrespondencePgStore('synthetic-owner-connection', static fn(): ?string => 'The Narrator', static fn(): bool => true);
$deliveredStatus = qd_correspondence_project_result(
    $deliveredService, $deliveredProjectionStore, 'synthetic-owner-connection', $documentId, $requestId,
);
receiptCheckExpect(($deliveredStatus['state'] ?? null) === 'delivered'
    && ($deliveredStatus['receipt']['receivedAt'] ?? null) === '2026-11-10T12:34:56Z',
    'the actual dev33 receipt hash survives JSONB key order and compose status dates from the epoch localts, not native nanoseconds');
$inspectorContext = $context;
$inspectorContext['scopes'] = ['inspector'];
$mailboxRequest = (object)[
    'version' => 1,
    'requestId' => '99999999-9999-4999-8999-999999999991',
    'operation' => 'correspondence.mailbox.read',
    'context' => (object)['viewToken' => '44444444-4444-4444-8444-444444444444', 'nativeGeneration' => '1', 'serverContext' => 'synthetic-context'],
    'payload' => (object)['recipientActorKey' => 'npc:900002'],
];
$mailboxData = qd_correspondence_mailbox_read($mailboxRequest->payload, $inspectorContext);
$mailboxResponse = qd_response_with_bounded_data($mailboxRequest, $mailboxData, false);
receiptCheckExpect(($mailboxData['letters'][0]['receivedAt'] ?? null) === '2026-11-10T12:34:56Z'
    && ($mailboxData['limited'] ?? null) === false,
    'the inspector mailbox returns the same receipt date from the actual confirmed owner receipt');
$detailRequest = (object)[
    'version' => 1,
    'requestId' => '99999999-9999-4999-8999-999999999992',
    'operation' => 'correspondence.detail.read',
    'context' => $mailboxRequest->context,
    'payload' => (object)['recipientActorKey' => 'npc:900002', 'letterId' => $documentId],
];
$detailData = qd_correspondence_detail_read($detailRequest->payload, $inspectorContext);
$detailResponse = qd_response_with_bounded_data($detailRequest, $detailData, false);
receiptCheckExpect(($detailData['receivedAt'] ?? null) === '2026-11-10T12:34:56Z'
    && ($detailData['deliveryConfirmed'] ?? null) === true,
    'the inspector detail response returns the same receipt date from the actual confirmed owner receipt');
$tamperedMemory = clone $deliveredMemory;
$tamperedMemory->operations[$ownerOperationId]['receipt']['reason'] = 'tampered';
$GLOBALS['receiptCheckMemory'] = $tamperedMemory;
$tamperedProjectionStore = new CorrespondencePgStore('synthetic-owner-connection', static fn(): ?string => 'The Narrator', static fn(): bool => true);
$tamperedReceipt = qd_correspondence_validate_receipt(
    $tamperedProjectionStore,
    'synthetic-owner-connection',
    $tamperedProjectionStore->loadDocument($documentId),
    $tamperedProjectionStore->loadOperation($ownerOperationId),
    static fn(array $item): array => $tamperedProjectionStore->physicalItem($item),
    static fn(array $actor): array => $tamperedProjectionStore->physicalActor($actor),
    [],
    true,
);
receiptCheckExpect(($tamperedReceipt['confirmed'] ?? null) === false,
    'a valid-looking receipt with a changed field stays unconfirmed when its original signed hash no longer matches');
$GLOBALS['receiptCheckMemory'] = $deliveredMemory;

$unknownMemory = clone $memory;
$unknownMemory->executor = 'The Narrator';
$unknownService = new CorrespondenceService($unknownMemory);
$unknownOperation = $unknownMemory->operations[$ownerOperationId];
$unknownService->receive([
    'protocol_version' => 1,
    'document_id' => $documentId,
    'operation_id' => $ownerOperationId,
    'receipt_id' => $unknownOperation['receipt_id'],
    'playthrough_id' => $unknownOperation['playthrough_id'],
    'scope_token' => $unknownOperation['scope_token'],
    'nonce' => $unknownOperation['nonce'],
    'status' => 'unknown',
    'reason' => 'synthetic_partial',
    'note_form_id' => 0,
    'attachments' => [[
        'form_id' => 15,
        'quantity' => 1,
        'before_source' => 10,
        'after_source' => 9,
        'before_recipient' => 0,
        'after_recipient' => 1,
    ]],
]);
receiptCheckNormalizeEvents($unknownMemory);
receiptCheckExpect(($unknownService->get($documentId)['state'] ?? null) === 'unknown', 'the actual frozen owner classifies the valid unknown receipt as unknown');
$GLOBALS['receiptCheckMemory'] = $unknownMemory;
$unknownProjectionStore = new CorrespondencePgStore(
    'synthetic-owner-connection',
    static fn(): ?string => 'The Narrator',
    static fn(): bool => true,
);
$unknownDocumentIntegrity = qd_correspondence_document_integrity($unknownProjectionStore,
    $unknownProjectionStore->loadDocument($documentId), []);
$unknownProjectedResult = qd_correspondence_project_result($unknownService, $unknownProjectionStore,
    'synthetic-owner-connection', $documentId, $requestId, null, null, 'not_dispatched', true);
$unknownReceiptProjection = qd_correspondence_validate_receipt(
    $unknownProjectionStore,
    'synthetic-owner-connection',
    $unknownProjectionStore->loadDocument($documentId),
    $unknownProjectionStore->loadOperation($ownerOperationId),
    static fn(array $item): array => $unknownProjectionStore->physicalItem($item),
    static fn(array $actor): array => $unknownProjectionStore->physicalActor($actor),
    [],
    true,
);
$unknownReceiptProjectionDefaultResolvers = qd_correspondence_validate_receipt(
    $unknownProjectionStore,
    'synthetic-owner-connection',
    $unknownProjectionStore->loadDocument($documentId),
    $unknownProjectionStore->loadOperation($ownerOperationId),
    null,
    null,
    [],
    true,
);
receiptCheckExpect(($unknownReceiptProjection['partial'] ?? null) === true,
    'the valid frozen owner unknown receipt has real partial-transfer evidence in the Department receipt validator: ' . json_encode($unknownReceiptProjection, JSON_UNESCAPED_SLASHES));
receiptCheckExpect(($unknownReceiptProjection['confirmed'] ?? null) === false,
    'the owner unknown outcome remains unconfirmed even when it records partial transfer evidence');
receiptCheckExpect(($unknownReceiptProjectionDefaultResolvers['partial'] ?? null) === true,
    'fenced adapter resolvers preserve the same unknown receipt projection: ' . json_encode([
        'projection' => $unknownReceiptProjectionDefaultResolvers,
        'version' => qd_correspondence_loaded_source_version(),
        'usesRestoreFence' => qd_correspondence_uses_restore_fence(),
        'physicalItem' => $unknownProjectionStore->physicalItem($unknownProjectionStore->loadDocument($documentId)['attachments'][0]),
    ], JSON_UNESCAPED_SLASHES));
receiptCheckExpect($unknownDocumentIntegrity && ($unknownProjectedResult['state'] ?? null) === 'partial',
    'the same complete saved document projects partial before receipt.check gating: ' . json_encode(['integrity' => $unknownDocumentIntegrity, 'result' => $unknownProjectedResult], JSON_UNESCAPED_SLASHES));
$unknownSaved = correspondenceCanonicalJson([$unknownMemory->docs, $unknownMemory->operations, $unknownMemory->notes]);
$unknownBeforeCommands = count($unknownMemory->commands);
$unknownBeforeDeliveries = count(array_filter($unknownMemory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Deliver@')));
$unknownPartial = receiptCheckRun($unknownMemory, $requestId, '66666666-6666-4666-8666-666666666666', $context);
receiptCheckExpect(($unknownPartial['data']['queued'] ?? null) === true && array_key_exists('reason', $unknownPartial['data']) && $unknownPartial['data']['reason'] === null
    && ($unknownPartial['data']['result']['state'] ?? null) === 'partial',
    'the actual owner unknown state can queue one receipt-only Reconcile while Department preserves its partial projection: ' . json_encode($unknownPartial['data'], JSON_UNESCAPED_SLASHES));
receiptCheckExpect(count($unknownMemory->commands) === $unknownBeforeCommands + 1
    && str_contains(end($unknownMemory->commands)['action'], 'ExtCmdCHIMCorrespondenceBridge_Reconcile@')
    && count(array_filter($unknownMemory->commands, static fn(array $command): bool => str_contains($command['action'], 'ExtCmdCHIMCorrespondenceBridge_Deliver@'))) === $unknownBeforeDeliveries,
    'unknown-owner receipt check queues only Reconcile and never a second Deliver');
receiptCheckExpect(correspondenceCanonicalJson([$unknownMemory->docs, $unknownMemory->operations, $unknownMemory->notes]) === $unknownSaved,
    'unknown-owner receipt query preserves the exact saved partial receipt, operation, document, and attachments');
$unknownPartialJson = json_encode($unknownPartial['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$beforeNoExecutor = correspondenceCanonicalJson([$memory->docs, $memory->operations, $memory->notes]);
$beforeNoExecutorCommands = count($memory->commands);
$memory->executor = null;
$ownerNoExecutor = $service->dispatch($documentId, true);
receiptCheckExpect(($ownerNoExecutor['state'] ?? null) === 'dispatched'
    && ($ownerNoExecutor['reason'] ?? null) === 'executor_unavailable'
    && !array_key_exists('operation_id', $ownerNoExecutor),
    'the actual frozen owner returns its existing dispatched state and executor refusal without operation_id');
receiptCheckExpect(count($memory->commands) === $beforeNoExecutorCommands,
    'the actual owner no-executor branch queues no command');
$noExecutor = receiptCheckRun($memory, $requestId, '55555555-5555-4555-8555-555555555555', $context);
receiptCheckExpect(($noExecutor['data']['operationId'] ?? null) === $requestId && ($noExecutor['data']['queued'] ?? null) === false
    && ($noExecutor['data']['reason'] ?? null) === 'executor_unavailable'
    && ($noExecutor['data']['result']['state'] ?? null) === 'dispatched',
    'owner executor refusal is reported as queued=false without turning into a conflict');
receiptCheckExpect(count($memory->commands) === $beforeNoExecutorCommands
    && correspondenceCanonicalJson([$memory->docs, $memory->operations, $memory->notes]) === $beforeNoExecutor,
    'executor refusal queues nothing and leaves the owner document and operation unchanged');
receiptCheckExpect(qd_canonical_json($success['data']['result']) === qd_canonical_json($noExecutor['data']['result']),
    'no-executor receipt refusal preserves the existing result projection');
$noExecutorJson = json_encode($noExecutor['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$closedMemory = new CorrespondenceMemoryStore();
$closedMemory->executor = null;
$closedService = new CorrespondenceService($closedMemory);
$closedRequestId = '77777777-7777-4777-8777-777777777777';
$closed = $closedService->compose([
    'request_id' => $closedRequestId,
    'author' => 'player',
    'payer' => 'player',
    'recipient' => 'npc:900002',
    'claimed_sender' => 'player',
    'body' => 'Synthetic closed-queue receipt-check fixture.',
    'attachments' => [['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 500]],
]);
$closedService->close($closed['document_id']);
receiptCheckNormalizeEvents($closedMemory);
$closedSaved = correspondenceCanonicalJson([$closedMemory->docs, $closedMemory->operations, $closedMemory->notes]);
$closedReply = receiptCheckRun($closedMemory, $closedRequestId, '88888888-8888-4888-8888-888888888888', $context);
receiptCheckExpect(($closedReply['data']['queued'] ?? null) === false && ($closedReply['data']['reason'] ?? null) === 'operator_closed'
    && ($closedReply['data']['result']['state'] ?? null) === 'queued', 'the synthetic closed-queue 500-gold fixture remains refused with its status projection');
receiptCheckExpect($closedMemory->commands === [] && correspondenceCanonicalJson([$closedMemory->docs, $closedMemory->operations, $closedMemory->notes]) === $closedSaved,
    'closed-queue receipt check queues no native work and preserves the synthetic 500-gold attachment');

$negativeCases = [];
$GLOBALS['receiptCheckMemory'] = $memory;
$wrongOperationId = '99999999-9999-4999-8999-999999999999';
try {
    receiptCheckRun($memory, $wrongOperationId, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $context);
    throw new RuntimeException('A request UUID without a saved document was accepted.');
} catch (QDServerFault $error) {
    receiptCheckExpect($error->wireCode === 'not_found', 'wrong saved request identity fails closed');
    $negativeCases['wrong_request_id'] = 'not_found';
}
foreach ([
    'contextStale' => 'stale_context',
    'restoreStale' => 'stale_context',
    'scopeChanged' => 'stale_context',
    'sourceChangedAtFence' => 'unavailable',
] as $flag => $expected) {
    $GLOBALS['receiptCheckFlags'] = [
        'contextStale' => false, 'restoreStale' => false, 'sourceChangedAtFence' => false,
        'sourceChanged' => false, 'scopeChanged' => false,
    ];
    $GLOBALS['receiptCheckFlags'][$flag] = true;
    $beforeCommands = count($memory->commands);
    try {
        receiptCheckRun($memory, $requestId, receiptCheckUuid(), $context);
        throw new RuntimeException('A stale ' . $flag . ' boundary was accepted.');
    } catch (QDServerFault $error) {
        receiptCheckExpect($error->wireCode === $expected, 'stale ' . $flag . ' boundary maps to its fixed refusal');
        receiptCheckExpect(count($memory->commands) === $beforeCommands, 'stale ' . $flag . ' boundary queues no native command');
        $negativeCases[$flag] = $error->wireCode;
    }
}
$GLOBALS['receiptCheckFlags'] = ['contextStale' => false, 'restoreStale' => false, 'sourceChangedAtFence' => false, 'sourceChanged' => false, 'scopeChanged' => false];

$report = [
    'status' => 'PASS',
    'owner' => 'exact staged dev33 source closure with the actual CorrespondenceService runtime',
    'adapter' => 'current Department receipt.check handler and result projection functions',
    'cases' => [
        'dispatched_receipt_check_synthetic' => ['queued' => true, 'reason' => null, 'newReconcileCommands' => 1, 'newDeliverCommands' => 0],
        'unknown_owner_partial_projection' => ['ownerState' => 'unknown', 'projectionState' => 'partial', 'queued' => true, 'newReconcileCommands' => 1, 'newDeliverCommands' => 0],
        'no_executor_same_dispatched_document' => ['queued' => false, 'reason' => 'executor_unavailable', 'newCommands' => 0, 'projectionUnchanged' => true],
        'closed_queue_receipt_check_synthetic' => ['queued' => false, 'reason' => 'operator_closed', 'newCommands' => 0, 'attachmentsUnchanged' => true],
        'confirmed_receipt_uses_epoch_localts' => ['nativeTs' => '1794314096000000000', 'localts' => '1794314096', 'jsonbOrderFallback' => true,
            'compose' => $deliveredStatus['receipt']['receivedAt'], 'mailbox' => $mailboxData['letters'][0]['receivedAt'], 'detail' => $detailData['receivedAt'],
            'tamperedConfirmed' => $tamperedReceipt['confirmed'], 'unknownConfirmed' => $unknownReceiptProjection['confirmed']],
        'negative_refusals' => $negativeCases,
    ],
    'assertCurrentCalls' => $assertions,
    'beginCommitCalls' => $commits,
    'substitutions' => ['PostgreSQL connection, core/plugin read rows and restore snapshot use synthetic in-memory values; the owner receipt row uses a 19-digit native nanosecond ts, epoch-second localts, and JSONB-style key ordering. No external data is present.'],
    'unperformed' => ['database concurrency', 'durable journal recovery on Windows exFAT because the required directory fsync may fail'],
    'liveAccess' => false,
    'providerCalls' => 0,
    'gameAccess' => false,
];

if (is_string($emitDir)) {
    $emitRoot = realpath($emitDir);
    if (!is_string($emitRoot) || !is_dir($emitRoot)) throw new RuntimeException('The approved artifact output directory must already exist.');
    $pairs = [
        'receipt-check-queued.json' => ['request' => $success['request'], 'response' => $success['response']],
        'receipt-check-noexecutor.json' => ['request' => $noExecutor['request'], 'response' => $noExecutor['response']],
        'receipt-check-unknown-partial.json' => ['request' => $unknownPartial['request'], 'response' => $unknownPartial['response']],
        'correspondence-mailbox-delivered.json' => ['request' => $mailboxRequest, 'response' => $mailboxResponse],
        'correspondence-detail-delivered.json' => ['request' => $detailRequest, 'response' => $detailResponse],
    ];
    foreach ($pairs as $name => $pair) {
        $path = $emitRoot . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, json_encode($pair, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not emit the synthetic receipt-check pair.');
        }
    }
    if (file_put_contents($emitRoot . DIRECTORY_SEPARATOR . 'receipt-check-offline-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not emit the receipt-check report.');
    }
}

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fclose($GLOBALS['receiptCheckLease']);
