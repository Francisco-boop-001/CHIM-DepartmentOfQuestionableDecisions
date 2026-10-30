<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';

function resumeContractExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function resumeContractUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function resumeContractRequest(string $operation, object $payload): stdClass
{
    return (object)[
        'version' => 1,
        'requestId' => resumeContractUuid(),
        'operation' => $operation,
        'context' => (object)['viewToken' => resumeContractUuid(), 'nativeGeneration' => '1', 'serverContext' => 'fixture'],
        'payload' => $payload,
    ];
}

$operationId = '11111111-1111-4111-8111-111111111111';
$attemptId = '22222222-2222-4222-8222-222222222222';
$payload = (object)['operationId' => $operationId, 'attemptId' => $attemptId];
resumeContractExpect(qd_validate_payload('correspondence.resume', $payload) === 'ok', 'resume must accept the exact original-letter and fresh-attempt payload');
resumeContractExpect(qd_validate_payload('correspondence.resume', (object)['operationId' => $operationId, 'attemptId' => $attemptId, 'retry' => true]) === 'invalid_request', 'resume must reject extra payload fields');
resumeContractExpect(qd_validate_payload('correspondence.resume', (object)['operationId' => $operationId]) === 'invalid_request', 'resume must require an explicit attempt ID');
resumeContractExpect(qd_validate_payload('correspondence.resume', (object)['operationId' => 'not-a-uuid', 'attemptId' => $attemptId]) === 'invalid_request', 'resume must validate the original request UUID');
resumeContractExpect(qd_validate_payload('correspondence.resume', (object)['operationId' => $operationId, 'attemptId' => 'not-a-uuid']) === 'invalid_request', 'resume must validate the fresh attempt UUID');
resumeContractExpect((QD_OPERATION_REQUIREMENTS['correspondence.resume']['scope'] ?? null) === 'compose', 'resume must require compose scope');
resumeContractExpect(in_array('correspondence.resume', QD_MUTATION_OPERATIONS, true), 'resume must enter the Department mutation journal');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)[]) === 'ok', 'pending discovery must accept an initial page request');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['cursor' => str_repeat('x', 512)]) === 'ok', 'pending discovery must accept an opaque bounded cursor');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['cursor' => str_repeat('x', 513)]) === 'invalid_request', 'pending discovery must reject an oversized cursor');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['cursor' => '']) === 'invalid_request', 'pending discovery must reject an empty continuation cursor');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['cursor' => null]) === 'invalid_request', 'pending discovery must reject a null continuation cursor');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['cursor' => 'opaque', 'limit' => 25]) === 'invalid_request', 'pending discovery must reject extra payload fields');
resumeContractExpect(qd_page_field('correspondence.pending.read') === 'operations'
    && qd_operation_has_cursor('correspondence.pending.read'), 'pending discovery must use the bounded operations page and cursor contract');
resumeContractExpect(qd_validate_payload('correspondence.pending.read', (object)['limit' => 25]) === 'invalid_request', 'pending discovery must own its fixed bound');
resumeContractExpect((QD_OPERATION_REQUIREMENTS['correspondence.pending.read']['scope'] ?? null) === 'compose', 'pending discovery must require compose scope');
resumeContractExpect(!in_array('correspondence.pending.read', QD_MUTATION_OPERATIONS, true), 'pending discovery must remain a read operation');
resumeContractExpect(array_key_exists('correspondence.pending.read', qd_correspondence_runtime_operations()), 'pending discovery must be wired through the correspondence adapter');
$pendingRequest = resumeContractRequest('correspondence.pending.read', (object)[]);
$pendingResponse = qd_response_with_bounded_data($pendingRequest, (object)['operations' => [], 'limited' => false, 'nextCursor' => null], false);
resumeContractExpect(array_keys(get_object_vars($pendingResponse['data'])) === ['operations', 'limited', 'nextCursor'], 'pending response must preserve the exact operations, limited, nextCursor shape');
$validPendingRow = (object)[
    'operationId' => $operationId,
    'recipientLabel' => 'Rhea',
    'state' => 'queued',
    'hasTransfer' => false,
    'canResume' => true,
    'queuedReason' => 'not_dispatched',
    'operatorClosed' => false,
];
$validPendingData = (object)['operations' => [$validPendingRow], 'limited' => false, 'nextCursor' => null];
$validPendingResponse = qd_response_with_bounded_data($pendingRequest, $validPendingData, false);
resumeContractExpect(($validPendingResponse['data']->operations[0]->canResume ?? null) === true, 'pending response must accept the exact queued recovery row shape');
$malformedDocumentProjection = (object)[
    'operationId' => $operationId,
    'recipientLabel' => 'Unknown recipient',
    'state' => 'unknown',
    'hasTransfer' => true,
    'canResume' => false,
    'queuedReason' => null,
    'operatorClosed' => false,
];
$malformedFirstPage = qd_response_with_bounded_data($pendingRequest,
    (object)['operations' => [$malformedDocumentProjection], 'limited' => true, 'nextCursor' => 'next-page'], false);
$cleanFinalPage = qd_response_with_bounded_data($pendingRequest,
    (object)['operations' => [], 'limited' => false, 'nextCursor' => null], false);
resumeContractExpect(($malformedFirstPage['data']->operations[0]->state ?? null) === 'unknown'
    && ($malformedFirstPage['data']->operations[0]->hasTransfer ?? null) === true
    && ($malformedFirstPage['data']->limited ?? null) === true
    && ($cleanFinalPage['data']->operations ?? null) === []
    && ($cleanFinalPage['data']->limited ?? null) === false,
    'malformed_first_page_placeholder_must_remain_transfer_risk_through_a_clean_final_page');
$legacyStoredRow = (object)array_replace(get_object_vars($validPendingRow), ['state' => 'stored', 'canResume' => false, 'queuedReason' => null]);
$legacyStoredResponse = qd_response_with_bounded_data($pendingRequest, (object)['operations' => [$legacyStoredRow], 'limited' => false, 'nextCursor' => null], false);
resumeContractExpect(($legacyStoredResponse['data']->operations[0]->state ?? null) === 'stored', 'pending response must continue accepting legacy stored rows');
foreach ([
    'extra row field' => (object)(get_object_vars($validPendingRow) + ['debug' => true]),
    'invalid operation ID' => (object)array_replace(get_object_vars($validPendingRow), ['operationId' => 'invalid']),
    'invalid label type' => (object)array_replace(get_object_vars($validPendingRow), ['recipientLabel' => false]),
    'invalid state' => (object)array_replace(get_object_vars($validPendingRow), ['state' => 'sending']),
    'invalid transfer flag' => (object)array_replace(get_object_vars($validPendingRow), ['hasTransfer' => 0]),
    'resume while operator closed' => (object)array_replace(get_object_vars($validPendingRow), ['operatorClosed' => true]),
    'resume dispatched row' => (object)array_replace(get_object_vars($validPendingRow), ['state' => 'dispatched', 'hasTransfer' => true, 'queuedReason' => null]),
    'unknown row without conservative transfer risk' => (object)array_replace(get_object_vars($validPendingRow), ['state' => 'unknown', 'hasTransfer' => false, 'canResume' => false, 'queuedReason' => null]),
    'reason on nonqueued row' => (object)array_replace(get_object_vars($validPendingRow), ['state' => 'partial', 'hasTransfer' => true, 'canResume' => false]),
    'unknown queued reason' => (object)array_replace(get_object_vars($validPendingRow), ['queuedReason' => 'private_error']),
    'queued row without reason' => (object)array_replace(get_object_vars($validPendingRow), ['queuedReason' => null]),
] as $case => $row) {
    $accepted = false;
    try {
        qd_response_with_bounded_data($pendingRequest, (object)['operations' => [$row], 'limited' => false, 'nextCursor' => null], false);
        $accepted = true;
    } catch (QDServerFault $error) {
        resumeContractExpect($error->wireCode === 'unavailable', 'invalid pending row must fail closed at the dispatcher boundary: ' . $case);
    }
    resumeContractExpect(!$accepted, 'invalid pending row unexpectedly accepted: ' . $case);
}
try {
    qd_response_with_bounded_data($pendingRequest, (object)['operations' => [], 'limited' => false, 'nextCursor' => null, 'debug' => true], false);
    throw new RuntimeException('pending response with an extra field unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'unavailable', 'pending response must reject extra fields');
}
try {
    qd_response_with_bounded_data($pendingRequest, (object)['operations' => [], 'limited' => true, 'nextCursor' => null], false);
    throw new RuntimeException('pending response claiming a limited final page unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'unavailable', 'pending limited must be true exactly when a continuation cursor exists');
}
$tooManyPendingRows = array_fill(0, 26, (object)['operationId' => resumeContractUuid()]);
try {
    qd_response_with_bounded_data($pendingRequest, (object)['operations' => $tooManyPendingRows, 'limited' => true, 'nextCursor' => 'cursor'], false);
    throw new RuntimeException('pending response with more than 25 rows unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'unavailable', 'pending response must enforce its 25-row page bound');
}

$contextHeld = false;
$assertOutsideCalls = 0;
$contextResult = qd_correspondence_in_current_context(
    ['withCurrentContext' => static function (callable $work) use (&$contextHeld): mixed {
        if ($contextHeld) throw new RuntimeException('context_lock_reentered');
        $contextHeld = true;
        try { return $work(); }
        finally { $contextHeld = false; }
    }],
    static function () use (&$contextHeld, &$assertOutsideCalls): void {
        if ($contextHeld) throw new RuntimeException('context_assert_reentered_under_lock');
        $assertOutsideCalls++;
    },
    static function () use (&$contextHeld): string {
        resumeContractExpect($contextHeld, 'work must execute while the current-context lock is held');
        return 'complete';
    },
);
resumeContractExpect($contextResult === 'complete' && !$contextHeld && $assertOutsideCalls === 1, 'post-context assertion must run after releasing the non-reentrant context lock');

$request = resumeContractRequest('correspondence.resume', $payload);
resumeContractExpect(qd_operation_id($request) === $attemptId, 'resume journal identity must use attemptId');
$semanticPayload = qd_operation_semantic_payload($request);
resumeContractExpect(($semanticPayload->operationId ?? null) === $operationId && !property_exists($semanticPayload, 'attemptId'), 'resume semantic identity must retain the original operationId and omit only attemptId');
$otherLetter = resumeContractRequest('correspondence.resume', (object)['operationId' => resumeContractUuid(), 'attemptId' => resumeContractUuid()]);
resumeContractExpect(qd_canonical_json(qd_operation_semantic_payload($request)) !== qd_canonical_json(qd_operation_semantic_payload($otherLetter)), 'different letters must keep distinct semantic journal slots');

$document = [
    'operation_id' => null,
    'payer' => ['kind' => 'player'],
];
resumeContractExpect(qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, false, false, false), 'an intact manual zero-operation SF30 letter should be resumable');
foreach (['dispatched', 'unknown', 'partial', 'delivered', 'rejected'] as $state) {
    resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, $state, true, false, false, false), 'non-queued state must refuse resume: ' . $state);
}
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.29', $document, 'queued', true, false, false, false), 'resume must remain pinned to SF30');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', false, false, false, false), 'integrity failure must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, true, false, false), 'companion ownership must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, false, true, false), 'autonomy-child ownership must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, false, null, false), 'unknown autonomy ownership must fail closed');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document + ['operator_close' => []], 'queued', true, false, false, false), 'operator-closed letters must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', array_replace($document, ['operation_id' => $operationId]), 'queued', true, false, false, false), 'linked operations must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, false, false, true), 'orphan owner operation rows must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', $document, 'queued', true, false, false, false, true), 'orphan dispatch anchors must refuse resume');
resumeContractExpect(!qd_correspondence_resume_eligible('0.1.0-dev.30', ['operation_id' => null, 'payer' => ['kind' => 'npc']], 'queued', true, false, false, false), 'non-player-paid letters must refuse resume');

resumeContractExpect(qd_correspondence_safe_queue_reason(new DomainException('renderer_content_overflow')) === 'renderer_unavailable', 'known renderer failures must map to a fixed renderer code');
resumeContractExpect(qd_correspondence_safe_queue_reason(new DomainException('renderer_unavailable')) === 'renderer_unavailable', 'pinned owner renderer failure must map to its fixed renderer code');
resumeContractExpect(qd_correspondence_safe_queue_reason(new DomainException('actor_identity_changed')) === 'identity_changed', 'known identity failures must map to a fixed identity code');
resumeContractExpect(qd_correspondence_safe_queue_reason(new DomainException('renderer_private_body_secret')) === 'operation_unavailable', 'unknown owner exception messages must not leak or be overclassified');
resumeContractExpect(qd_correspondence_safe_queue_reason(new RuntimeException('private body must not leak')) === 'operation_unavailable', 'unknown exceptions must map to a fixed safe fallback');

if (PHP_OS_FAMILY === 'Windows') {
    fwrite(STDOUT, "SKIP: guarded bootstrap-state assertions run in the isolated Linux API harness\n");
} else {
$bootstrapTestRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pending-context-' . bin2hex(random_bytes(8));
$bootstrapWebRoot = $bootstrapTestRoot . DIRECTORY_SEPARATOR . 'web';
$bootstrapPrivateRoot = $bootstrapTestRoot . DIRECTORY_SEPARATOR . 'private';
mkdir($bootstrapWebRoot, 0700, true);
mkdir($bootstrapPrivateRoot, 0700, true);
try {
    $bootstrapSettings = [
        'pairingPath' => $bootstrapPrivateRoot . DIRECTORY_SEPARATOR . 'pairing.json',
        'statePath' => $bootstrapPrivateRoot . DIRECTORY_SEPARATOR . 'state.json',
        'webRoot' => $bootstrapWebRoot,
        'privateRoot' => $bootstrapPrivateRoot,
    ];
    $pair = qd_pair_issue($bootstrapSettings['pairingPath'], $bootstrapWebRoot, ['compose', 'pcv'], $bootstrapPrivateRoot);
    $authorization = 'Bearer ' . $pair['token'];
    $auth = qd_authenticate($bootstrapSettings['pairingPath'], $bootstrapWebRoot, $authorization, $bootstrapPrivateRoot);
    resumeContractExpect(is_array($auth), 'private cursor key fixture must authenticate');
    $capabilities = ['pcv' => true, 'mp' => false, 'sas' => false, 'correspondence' => true, 'brainwash' => false];
    $bootstrapDependencies = [
        'currentScope' => static fn(string $pairId): string => 'pending-contract-scope',
        'readiness' => static fn(bool $includePcv = true): array => $capabilities,
    ];
    $bootstrapRequest = resumeContractRequest('bootstrap', (object)[]);
    $bootstrapRequest->context->serverContext = null;
    $bootstrapResponse = qd_bootstrap_authenticated_response($bootstrapRequest, $authorization, $bootstrapSettings, $bootstrapDependencies, $auth);
    $bootstrapData = $bootstrapResponse['data'];
    $bootstrapKeys = array_keys(get_object_vars($bootstrapData));
    sort($bootstrapKeys, SORT_STRING);
    resumeContractExpect($bootstrapKeys === ['access', 'authenticated', 'authRequired', 'capabilities', 'scopeToken', 'serverContext'], 'bootstrap wire keys must stay unchanged when adding private pending cursors');
    resumeContractExpect(!property_exists($bootstrapData, 'pendingReadMacKey')
        && qd_canonical_json($bootstrapData->capabilities) === qd_canonical_json((object)$capabilities), 'private cursor key must not be exposed and the five capability flags must stay unchanged');

    $guardedState = file_get_contents($bootstrapSettings['statePath']);
    resumeContractExpect(is_string($guardedState) && str_starts_with($guardedState, QD_GUARDED_JSON_PREFIX), 'private pending key must live only in the existing guarded context state');
    $state = json_decode(substr($guardedState, strlen(QD_GUARDED_JSON_PREFIX)), true, 16, JSON_THROW_ON_ERROR);
    $contextRecord = $state['contexts'][$pair['pairId']] ?? null;
    $privatePendingKey = is_array($contextRecord) ? ($contextRecord['pendingReadMacKey'] ?? null) : null;
    resumeContractExpect(is_string($privatePendingKey) && preg_match('/\A[a-f0-9]{64}\z/D', $privatePendingKey) === 1, 'bootstrap must persist a private 32-byte per-context cursor key');

    qd_server_state_transaction($bootstrapSettings, static function (array &$currentState) use ($pair): void {
        unset($currentState['contexts'][$pair['pairId']]['pendingReadMacKey']);
    });
    $legacyStateHash = hash_file('sha256', $bootstrapSettings['statePath']);
    $legacyContext = $bootstrapData->serverContext;
    $legacyViewToken = $bootstrapRequest->context->viewToken;
    $operationDependencies = $bootstrapDependencies + [
        'operations' => [
            'pcv.status.read' => static fn(): array => ['armed' => false],
            'correspondence.pending.read' => static fn(): array => ['operations' => [], 'limited' => false, 'nextCursor' => null],
        ],
    ];
    $ordinaryRequest = resumeContractRequest('pcv.status.read', (object)[]);
    $ordinaryRequest->context->viewToken = $legacyViewToken;
    $ordinaryRequest->context->serverContext = $legacyContext;
    $ordinaryResponse = qd_server_dispatch($ordinaryRequest, $authorization, $bootstrapSettings, $operationDependencies);
    resumeContractExpect(($ordinaryResponse['ok'] ?? null) === true, 'legacy context without a pending key must continue to serve unrelated operations');
    $legacyPendingRequest = resumeContractRequest('correspondence.pending.read', (object)[]);
    $legacyPendingRequest->context->viewToken = $legacyViewToken;
    $legacyPendingRequest->context->serverContext = $legacyContext;
    $legacyPendingResponse = qd_server_dispatch($legacyPendingRequest, $authorization, $bootstrapSettings, $operationDependencies);
    resumeContractExpect(($legacyPendingResponse['error']['code'] ?? null) === 'stale_context'
        && hash_equals($legacyStateHash, hash_file('sha256', $bootstrapSettings['statePath'])), 'legacy pending discovery must fail closed without rotating or writing a missing key');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bootstrapTestRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($bootstrapTestRoot);
}
}

$pendingKey = bin2hex(random_bytes(32));
$pendingBinding = hash('sha256', 'pending binding fixture');
$pendingUpper = ['createdAt' => '2026-10-30 12:00:00.123456+00', 'documentId' => '11111111-1111-4111-8111-111111111111'];
$pendingLast = ['createdAt' => '2026-10-30 11:59:59.000001+00', 'documentId' => '22222222-2222-4222-8222-222222222222'];
$pendingCursor = qd_correspondence_pending_cursor_create($pendingBinding, $pendingUpper, $pendingLast, $pendingKey, '27');
$decodedPendingCursor = qd_correspondence_pending_cursor_parse($pendingCursor, $pendingBinding, $pendingKey);
resumeContractExpect($decodedPendingCursor === ['count' => '27', 'upper' => $pendingUpper, 'last' => $pendingLast], 'signed pending cursor must round-trip the scoped manual-row count, watermark, and last scanned tuple');
$maxDatabaseCount = '9223372036854775807';
$maxCountPendingCursor = qd_correspondence_pending_cursor_create($pendingBinding, $pendingUpper, $pendingLast, $pendingKey, $maxDatabaseCount);
resumeContractExpect(strlen($maxCountPendingCursor) <= 512
    && (qd_correspondence_pending_cursor_parse($maxCountPendingCursor, $pendingBinding, $pendingKey)['count'] ?? null) === $maxDatabaseCount,
    'signed pending cursor must fit the opaque 512-byte wire limit with a maximum database row count');
$tamperedPendingCursor = substr($pendingCursor, 0, -1) . (str_ends_with($pendingCursor, 'A') ? 'B' : 'A');
try {
    qd_correspondence_pending_cursor_parse($tamperedPendingCursor, $pendingBinding, $pendingKey);
    throw new RuntimeException('tampered pending cursor unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'stale_revision', 'tampered pending cursor must fail as stale_revision');
}
try {
    qd_correspondence_pending_cursor_parse($pendingCursor, $pendingBinding, bin2hex(random_bytes(32)));
    throw new RuntimeException('cursor signed with a rotated key unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'stale_revision', 'old context key must invalidate a pending cursor');
}
try {
    qd_correspondence_pending_cursor_parse($pendingCursor, hash('sha256', 'other scope and restore'), $pendingKey);
    throw new RuntimeException('cursor with a different binding unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'stale_context', 'pending cursor must bind scope, context, restore generation, and source identity');
}
$pendingCursorParts = explode('.', $pendingCursor);
$pendingCursorJson = base64_decode(strtr($pendingCursorParts[0], '-_', '+/') . str_repeat('=', (4 - strlen($pendingCursorParts[0]) % 4) % 4), true);
$expiredCursorData = is_string($pendingCursorJson) ? json_decode($pendingCursorJson, true) : null;
resumeContractExpect(is_array($expiredCursorData), 'pending cursor test token must decode for an authenticated expiry fixture');
$expiredCursorData['e'] = time() - 1;
$expiredCursorJson = json_encode($expiredCursorData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$expiredCursorPart = rtrim(strtr(base64_encode($expiredCursorJson), '+/', '-_'), '=');
$expiredCursorMac = hash_hmac('sha256', QD_CORRESPONDENCE_PENDING_CURSOR_DOMAIN . "\0" . $expiredCursorPart, hex2bin($pendingKey), true);
$expiredCursor = $expiredCursorPart . '.' . rtrim(strtr(base64_encode($expiredCursorMac), '+/', '-_'), '=');
try {
    qd_correspondence_pending_cursor_parse($expiredCursor, $pendingBinding, $pendingKey);
    throw new RuntimeException('expired pending cursor unexpectedly accepted');
} catch (QDServerFault $error) {
    resumeContractExpect($error->wireCode === 'stale_revision', 'expired pending cursor must fail as stale_revision');
}

if (PHP_OS_FAMILY === 'Windows') {
    fwrite(STDOUT, "SKIP: persistent journal directory-fsync assertions run in the isolated Linux API harness\n");
} else {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-resume-contract-' . bin2hex(random_bytes(8));
    $webRoot = $root . DIRECTORY_SEPARATOR . 'web';
    $privateRoot = $root . DIRECTORY_SEPARATOR . 'private';
    mkdir($webRoot, 0700, true);
    mkdir($privateRoot, 0700, true);
    $settings = [
        'pairingPath' => $privateRoot . DIRECTORY_SEPARATOR . 'pairing.json',
        'statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.json',
        'webRoot' => $webRoot,
    ];
    $pair = qd_pair_issue($settings['pairingPath'], $webRoot, ['compose']);
    $scopeHash = hash('sha256', 'resume-contract-scope');
    $semanticHash = hash('sha256', qd_canonical_json(qd_operation_semantic_payload($request)));
    $payloadHash = hash('sha256', qd_canonical_json($payload));
    $first = qd_journal_begin($settings, $pair['pairId'], $scopeHash, 'correspondence.resume', $attemptId, $payloadHash, $semanticHash);
    resumeContractExpect(($first['action'] ?? null) === 'dispatch', 'a first explicit resume attempt must reserve a journal entry');
    qd_journal_mark_commit($settings, $first['key']);
    qd_journal_complete($settings, $first['key'], json_encode((object)['operationId' => $operationId, 'state' => 'queued', 'canResume' => true, 'queuedReason' => 'executor_unavailable'], JSON_THROW_ON_ERROR));
    $replay = qd_journal_begin($settings, $pair['pairId'], $scopeHash, 'correspondence.resume', $attemptId, $payloadHash, $semanticHash);
    resumeContractExpect(($replay['action'] ?? null) === 'replay' && ($replay['data']->operationId ?? null) === $operationId, 'repeating one attempt ID must replay the result correlated to the original letter');
    $nextAttemptId = resumeContractUuid();
    $nextPayload = (object)['operationId' => $operationId, 'attemptId' => $nextAttemptId];
    $nextRequest = resumeContractRequest('correspondence.resume', $nextPayload);
    $nextSemanticHash = hash('sha256', qd_canonical_json(qd_operation_semantic_payload($nextRequest)));
    $next = qd_journal_begin($settings, $pair['pairId'], $scopeHash, 'correspondence.resume', $nextAttemptId, hash('sha256', qd_canonical_json($nextPayload)), $nextSemanticHash);
    resumeContractExpect(($next['action'] ?? null) === 'dispatch' && $nextSemanticHash === $semanticHash, 'a new explicit attempt must retry the same letter only after the prior attempt completes');
    qd_journal_abandon_no_write($settings, $next['key']);

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

fwrite(STDOUT, "PASS: resume wire, attempt identity, eligibility, safe reasons, and context-lock boundary\n");
