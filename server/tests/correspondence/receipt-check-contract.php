<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';

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

function receiptCheckRequest(string $operation, object $payload): stdClass
{
    return (object)[
        'version' => 1,
        'requestId' => receiptCheckUuid(),
        'operation' => $operation,
        'context' => (object)['viewToken' => receiptCheckUuid(), 'nativeGeneration' => '1', 'serverContext' => 'fixture'],
        'payload' => $payload,
    ];
}

$operationId = '11111111-1111-4111-8111-111111111111';
$attemptId = '22222222-2222-4222-8222-222222222222';
$payload = (object)['operationId' => $operationId, 'attemptId' => $attemptId];
receiptCheckExpect(qd_validate_payload('correspondence.receipt.check', $payload) === 'ok', 'receipt check accepts the exact original-request and fresh-attempt payload');
receiptCheckExpect(qd_validate_payload('correspondence.receipt.check', (object)['operationId' => $operationId, 'attemptId' => $attemptId, 'retry' => true]) === 'invalid_request', 'receipt check rejects extra payload fields');
receiptCheckExpect(qd_validate_payload('correspondence.receipt.check', (object)['operationId' => $operationId]) === 'invalid_request', 'receipt check requires a fresh attempt UUID');
receiptCheckExpect(qd_validate_payload('correspondence.receipt.check', (object)['operationId' => 'not-a-uuid', 'attemptId' => $attemptId]) === 'invalid_request', 'receipt check validates the original request UUID');
receiptCheckExpect((QD_OPERATION_REQUIREMENTS['correspondence.receipt.check']['scope'] ?? null) === 'compose', 'receipt check requires compose scope');
receiptCheckExpect(in_array('correspondence.receipt.check', QD_MUTATION_OPERATIONS, true), 'receipt check enters the Department mutation journal');
receiptCheckExpect(array_key_exists('correspondence.receipt.check', qd_correspondence_runtime_operations()), 'receipt check routes through the pinned Correspondence adapter');
receiptCheckExpect(qd_correspondence_version_supports_receipt_check('0.1.0-dev.33')
    && qd_correspondence_version_supports_receipt_check('0.1.0-dev.32')
    && qd_correspondence_version_supports_receipt_check('0.1.0-dev.31')
    && !qd_correspondence_version_supports_receipt_check('0.1.0-dev.30')
    && !qd_correspondence_version_supports_receipt_check('0.1.0-dev.34'), 'receipt check supports the exact reviewed dev31, dev32, and dev33 source closures');

$request = receiptCheckRequest('correspondence.receipt.check', $payload);
receiptCheckExpect(qd_operation_id($request) === $attemptId, 'receipt check journal identity uses attemptId');
$semanticPayload = qd_operation_semantic_payload($request);
receiptCheckExpect(($semanticPayload->operationId ?? null) === $operationId && !property_exists($semanticPayload, 'attemptId'), 'receipt check semantic identity retains operationId and omits attemptId');
$otherOriginal = receiptCheckRequest('correspondence.receipt.check', (object)['operationId' => receiptCheckUuid(), 'attemptId' => receiptCheckUuid()]);
receiptCheckExpect(qd_canonical_json(qd_operation_semantic_payload($request)) !== qd_canonical_json(qd_operation_semantic_payload($otherOriginal)), 'different saved requests keep distinct semantic journal slots');

$projection = (object)['operationId' => $operationId, 'state' => 'dispatched', 'canResume' => false];
$response = qd_response_with_bounded_data($request, (object)[
    'operationId' => $operationId,
    'queued' => true,
    'reason' => null,
    'result' => $projection,
], false);
receiptCheckExpect(array_keys(get_object_vars($response['data'])) === ['operationId', 'queued', 'reason', 'result'], 'receipt check response has exactly the agreed data fields');
receiptCheckExpect(($response['data']->queued ?? null) === true && property_exists($response['data'], 'reason') && $response['data']->reason === null, 'receipt check reports an accepted native reconciliation distinctly');
try {
    qd_response_with_bounded_data($request, (object)[
        'operationId' => $operationId,
        'queued' => false,
        'reason' => 'executor_unavailable',
        'result' => $projection,
        'debug' => true,
    ], false);
    throw new RuntimeException('receipt response with an extra field unexpectedly accepted');
} catch (QDServerFault $error) {
    receiptCheckExpect($error->wireCode === 'unavailable', 'receipt check response rejects extra fields');
}
try {
    qd_response_with_bounded_data($request, (object)[
        'operationId' => $operationId,
        'queued' => false,
        'reason' => null,
        'result' => $projection,
    ], false);
    throw new RuntimeException('receipt response with queued=false and no fixed refusal reason unexpectedly accepted');
} catch (QDServerFault $error) {
    receiptCheckExpect($error->wireCode === 'unavailable', 'queued=false requires one of the existing fixed queue-refusal reasons');
}

$adapterPath = (new ReflectionFunction('qd_correspondence_reconcile'))->getFileName();
$adapterSource = is_string($adapterPath) ? file_get_contents($adapterPath) : false;
receiptCheckExpect(is_string($adapterSource) && str_contains($adapterSource, 'function qd_correspondence_receipt_check('), 'receipt check adapter handler exists');
$reconcileStart = is_string($adapterSource) ? strpos($adapterSource, 'function qd_correspondence_reconcile(') : false;
$reconcileEnd = is_string($adapterSource) && is_int($reconcileStart) ? strpos($adapterSource, "\nfunction ", $reconcileStart + 1) : false;
$reconcileBody = is_string($adapterSource) && is_int($reconcileStart) && is_int($reconcileEnd) ? substr($adapterSource, $reconcileStart, $reconcileEnd - $reconcileStart) : '';
receiptCheckExpect(!str_contains($reconcileBody, '->dispatch('), 'status-only correspondence.reconcile remains read-only');
$receiptStart = is_string($adapterSource) ? strpos($adapterSource, 'function qd_correspondence_receipt_check(') : false;
$receiptEnd = is_string($adapterSource) && is_int($receiptStart) ? strpos($adapterSource, "\nfunction ", $receiptStart + 1) : false;
$receiptBody = is_string($adapterSource) && is_int($receiptStart) && is_int($receiptEnd) ? substr($adapterSource, $receiptStart, $receiptEnd - $receiptStart) : '';
receiptCheckExpect(substr_count($receiptBody, '->dispatch($documentId, true)') === 1, 'receipt check calls only the pinned service native-reconcile path');
receiptCheckExpect(!str_contains($receiptBody, '->retry(') && !str_contains($receiptBody, 'composeAndDispatch('), 'receipt check cannot retry or compose a delivery');

fwrite(STDOUT, "PASS: receipt-check protocol, journal identity, strict response shape, and status-only reconcile boundary\n");
